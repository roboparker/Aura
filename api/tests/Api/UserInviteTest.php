<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\GroupInvite;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserInvite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserInviteTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        // Order matters — group_invite -> user_invite -> user_group -> user.
        $this->entityManager->createQuery('DELETE FROM App\Entity\GroupInvite')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserInvite')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserGroup')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testInvitingExistingUserAddsThemDirectly(): void
    {
        $owner = $this->createUser('alice@example.com');
        $member = $this->createUser('bob@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'bob@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['status' => 'added']);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $this->assertCount(2, $reloaded->getMembers());
    }

    public function testInvitingUnknownEmailCreatesUserInviteAndGroupInvite(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'newcomer@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['status' => 'invited', 'email' => 'newcomer@example.com']);

        $this->entityManager->clear();
        $invites = $this->entityManager->getRepository(UserInvite::class)->findAll();
        $this->assertCount(1, $invites);
        $this->assertSame('newcomer@example.com', $invites[0]->getEmail());

        $groupInvites = $this->entityManager->getRepository(GroupInvite::class)->findAll();
        $this->assertCount(1, $groupInvites);
        $this->assertTrue($group->getId()->equals($groupInvites[0]->getGroup()->getId()));
    }

    public function testInvitingSameEmailToSecondGroupAttachesAdditionalGroupInvite(): void
    {
        $owner = $this->createUser('alice@example.com');
        $groupA = $this->createGroup($owner, 'Alpha', [$owner]);
        $groupB = $this->createGroup($owner, 'Beta', [$owner]);

        $client = static::createClient();
        $client->loginUser($owner);

        $client->request('POST', '/groups/' . $groupA->getId() . '/members', [
            'json' => ['email' => 'newcomer@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        $client->request('POST', '/groups/' . $groupB->getId() . '/members', [
            'json' => ['email' => 'newcomer@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        // Email is unique on UserInvite — only one row for the address.
        $this->assertCount(1, $this->entityManager->getRepository(UserInvite::class)->findAll());
        // But two GroupInvites under it, one per group.
        $this->assertCount(2, $this->entityManager->getRepository(GroupInvite::class)->findAll());
    }

    public function testReinvitingSameEmailToSameGroupIsIdempotent(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'newcomer@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'newcomer@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $this->assertCount(1, $this->entityManager->getRepository(UserInvite::class)->findAll());
        $this->assertCount(1, $this->entityManager->getRepository(GroupInvite::class)->findAll());
    }

    public function testInvitingRequiresValidEmail(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'not-an-email'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testNonOwnerMemberCannotInvite(): void
    {
        $owner = $this->createUser('alice@example.com');
        $member = $this->createUser('bob@example.com');
        $group = $this->createGroup($owner, 'Shared', [$owner, $member]);

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'newcomer@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testInviteLookupReturnsContextForValidToken(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);

        $token = $this->inviteEmail($owner, $group, 'newcomer@example.com');

        $client = static::createClient();
        $client->request('GET', '/invites/' . $token);
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'email' => 'newcomer@example.com',
            'groups' => [
                ['title' => 'Backend', 'invitedBy' => 'alice@example.com'],
            ],
        ]);
    }

    public function testInviteLookupReturns404ForBogusToken(): void
    {
        static::createClient()->request('GET', '/invites/not-a-real-token');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testOwnerCanListPendingInvitesForGroup(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);
        $this->inviteEmail($owner, $group, 'newcomer@example.com');

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('GET', '/groups/' . $group->getId() . '/invites');

        $this->assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();
        $this->assertCount(1, $body['invites']);
        $this->assertSame('newcomer@example.com', $body['invites'][0]['email']);
    }

    public function testNonOwnerMemberCannotListInvites(): void
    {
        $owner = $this->createUser('alice@example.com');
        $member = $this->createUser('bob@example.com');
        $group = $this->createGroup($owner, 'Shared', [$owner, $member]);

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('GET', '/groups/' . $group->getId() . '/invites');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanRevokeGroupInvite(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);
        $this->inviteEmail($owner, $group, 'newcomer@example.com');

        $invite = $this->entityManager->getRepository(GroupInvite::class)->findAll()[0];

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('DELETE', sprintf(
            '/groups/%s/invites/%s',
            $group->getId(),
            $invite->getId(),
        ));

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $this->assertCount(0, $this->entityManager->getRepository(GroupInvite::class)->findAll());
        // Last GroupInvite under the parent — UserInvite is removed too so
        // a stale token can't be redeemed for nothing.
        $this->assertCount(0, $this->entityManager->getRepository(UserInvite::class)->findAll());
    }

    public function testRevokingOneGroupKeepsParentInviteIfOthersExist(): void
    {
        $owner = $this->createUser('alice@example.com');
        $groupA = $this->createGroup($owner, 'Alpha', [$owner]);
        $groupB = $this->createGroup($owner, 'Beta', [$owner]);

        $this->inviteEmail($owner, $groupA, 'newcomer@example.com');
        $this->inviteEmail($owner, $groupB, 'newcomer@example.com');

        $repo = $this->entityManager->getRepository(GroupInvite::class);
        $invites = $repo->findAll();
        $invitesByGroupId = [];
        foreach ($invites as $invite) {
            $invitesByGroupId[(string) $invite->getGroup()->getId()] = $invite;
        }

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('DELETE', sprintf(
            '/groups/%s/invites/%s',
            $groupA->getId(),
            $invitesByGroupId[(string) $groupA->getId()]->getId(),
        ));
        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $this->assertCount(1, $this->entityManager->getRepository(UserInvite::class)->findAll());
        $this->assertCount(1, $this->entityManager->getRepository(GroupInvite::class)->findAll());
    }

    public function testSignupWithValidInviteTokenAutoJoinsAllInvitedGroups(): void
    {
        $owner = $this->createUser('alice@example.com');
        $groupA = $this->createGroup($owner, 'Alpha', [$owner]);
        $groupB = $this->createGroup($owner, 'Beta', [$owner]);
        $token = $this->inviteEmail($owner, $groupA, 'newcomer@example.com');
        // Same email invited to a second group — same UserInvite, new GroupInvite.
        $token = $this->inviteEmail($owner, $groupB, 'newcomer@example.com');

        $client = static::createClient();
        $client->request('POST', '/users', [
            'json' => [
                'email' => 'newcomer@example.com',
                'plainPassword' => 'password123',
                'givenName' => 'New',
                'familyName' => 'Comer',
                'inviteToken' => $token,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $newUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'newcomer@example.com']);
        $this->assertNotNull($newUser);

        $reloadedA = $this->entityManager->getRepository(UserGroup::class)->find($groupA->getId());
        $reloadedB = $this->entityManager->getRepository(UserGroup::class)->find($groupB->getId());
        $this->assertCount(2, $reloadedA->getMembers());
        $this->assertCount(2, $reloadedB->getMembers());

        $invite = $this->entityManager->getRepository(UserInvite::class)->findOneBy(['email' => 'newcomer@example.com']);
        $this->assertNotNull($invite->getAcceptedAt());
    }

    public function testSignupWithInviteTokenRejectsMismatchedEmail(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);
        $token = $this->inviteEmail($owner, $group, 'invited@example.com');

        $client = static::createClient();
        // Same token, but the user signs up with a different email — token
        // should not be honored.
        $client->request('POST', '/users', [
            'json' => [
                'email' => 'attacker@example.com',
                'plainPassword' => 'password123',
                'givenName' => 'Mal',
                'familyName' => 'Actor',
                'inviteToken' => $token,
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $this->assertCount(1, $reloaded->getMembers());

        $invite = $this->entityManager->getRepository(UserInvite::class)->findOneBy(['email' => 'invited@example.com']);
        $this->assertNull($invite->getAcceptedAt());
    }

    public function testSignupWithoutTokenDoesNotConsumePendingInvite(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);
        $this->inviteEmail($owner, $group, 'newcomer@example.com');

        $client = static::createClient();
        // Same email signs up without using the invite link.
        $client->request('POST', '/users', [
            'json' => [
                'email' => 'newcomer@example.com',
                'plainPassword' => 'password123',
                'givenName' => 'New',
                'familyName' => 'Comer',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        // Group membership unchanged — invite still pending until used.
        $this->assertCount(1, $reloaded->getMembers());
        $invite = $this->entityManager->getRepository(UserInvite::class)->findOneBy(['email' => 'newcomer@example.com']);
        $this->assertNull($invite->getAcceptedAt());
    }

    /**
     * Build a UserInvite + GroupInvite directly so the test has the plain
     * token (the controller hashes it before persistence; the only place
     * the plain token is observable is the email body, which is awkward to
     * intercept in ApiTestCase). Mirrors what UserGroupMemberController
     * does on the wire.
     */
    private function inviteEmail(User $owner, UserGroup $group, string $email): string
    {
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = new \DateTimeImmutable('+14 days');

        $invite = $this->entityManager->getRepository(UserInvite::class)
            ->findOneBy(['email' => $email]);
        if (null === $invite) {
            $invite = new UserInvite($email, $tokenHash, $expiresAt);
            $this->entityManager->persist($invite);
        } else {
            $invite->setTokenHash($tokenHash);
            $invite->setExpiresAt($expiresAt);
        }

        $existing = $this->entityManager->getRepository(GroupInvite::class)
            ->findOneBy(['userInvite' => $invite, 'group' => $group]);
        if (null === $existing) {
            new GroupInvite($invite, $group, $owner);
        }

        $this->entityManager->flush();

        return $plainToken;
    }

    /**
     * @param string[] $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param User[] $members
     */
    private function createGroup(User $owner, string $title, array $members): UserGroup
    {
        $group = new UserGroup();
        $group->setOwner($owner);
        $group->setTitle($title);
        foreach ($members as $member) {
            $group->addMember($member);
        }

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $group;
    }
}
