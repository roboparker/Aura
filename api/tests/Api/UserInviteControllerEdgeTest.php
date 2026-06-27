<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\GroupInvite;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserInvite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Edge-branch coverage for App\Controller\UserInviteController — the
 * resend endpoint (token rotation), unknown-invite / cross-group 404s,
 * and the non-owner / stranger authorization branches that the base
 * UserInviteTest doesn't exercise.
 */
class UserInviteControllerEdgeTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\GroupInvite')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserInvite')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserGroup')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testOwnerCanResendAndTokenRotates(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);
        $this->inviteEmail($owner, $group, 'newcomer@example.com');

        $invite = $this->entityManager->getRepository(GroupInvite::class)->findAll()[0];
        $oldHash = $invite->getUserInvite()->getTokenHash();

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', sprintf(
            '/groups/%s/invites/%s/resend',
            $group->getId(),
            $invite->getId(),
        ), ['headers' => ['Content-Type' => 'application/json']]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['status' => 'resent', 'email' => 'newcomer@example.com']);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(UserInvite::class)
            ->findOneBy(['email' => 'newcomer@example.com']);
        $this->assertNotNull($reloaded);
        // Token rotated → new authoritative link.
        $this->assertNotSame($oldHash, $reloaded->getTokenHash());
    }

    public function testResendUnknownInviteIs404(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('POST', sprintf(
            '/groups/%s/invites/%s/resend',
            $group->getId(),
            '00000000-0000-0000-0000-000000000000',
        ), ['headers' => ['Content-Type' => 'application/json']]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testResendInviteFromAnotherGroupIs404(): void
    {
        $owner = $this->createUser('alice@example.com');
        $groupA = $this->createGroup($owner, 'Alpha', [$owner]);
        $groupB = $this->createGroup($owner, 'Beta', [$owner]);
        // Invite attached to group A only.
        $this->inviteEmail($owner, $groupA, 'newcomer@example.com');
        $invite = $this->entityManager->getRepository(GroupInvite::class)->findAll()[0];

        $client = static::createClient();
        $client->loginUser($owner);
        // Address the group-A invite under group B's path → mismatch 404.
        $client->request('POST', sprintf(
            '/groups/%s/invites/%s/resend',
            $groupB->getId(),
            $invite->getId(),
        ), ['headers' => ['Content-Type' => 'application/json']]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonSpaceMemberCannotResend(): void
    {
        $owner = $this->createUser('alice@example.com');
        // Stranger: not in the group and not a member of its space.
        $stranger = $this->createUser('stranger@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);
        $this->inviteEmail($owner, $group, 'newcomer@example.com');
        $invite = $this->entityManager->getRepository(GroupInvite::class)->findAll()[0];

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('POST', sprintf(
            '/groups/%s/invites/%s/resend',
            $group->getId(),
            $invite->getId(),
        ), ['headers' => ['Content-Type' => 'application/json']]);

        // Not a member of the group's space → existence-hidden 404.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testStrangerListingInvitesIs404(): void
    {
        $owner = $this->createUser('alice@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/groups/' . $group->getId() . '/invites');
        // Non-member → existence hidden as 404.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRevokeUnknownInviteIs404(): void
    {
        $owner = $this->createUser('alice@example.com');
        $group = $this->createGroup($owner, 'Backend', [$owner]);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('DELETE', sprintf(
            '/groups/%s/invites/%s',
            $group->getId(),
            '00000000-0000-0000-0000-000000000000',
        ));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testRevokeOnUnknownGroupIs404(): void
    {
        $owner = $this->createUser('alice@example.com');
        $this->createGroup($owner, 'Backend', [$owner]);

        $client = static::createClient();
        $client->loginUser($owner);
        $client->request('DELETE', sprintf(
            '/groups/%s/invites/%s',
            '00000000-0000-0000-0000-000000000000',
            '00000000-0000-0000-0000-000000000001',
        ));
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Build a UserInvite + GroupInvite directly (mirrors UserInviteTest).
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
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param User[] $members
     */
    private function createGroup(User $owner, string $title, array $members): UserGroup
    {
        // Group owned by a fresh space with $owner as its only (admin) member.
        $space = new Space();
        $space->setName($title . ' space');
        $space->setCreatedBy($owner);
        $this->entityManager->persist($space);
        $admin = (new SpaceMembership())
            ->setUser($owner)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($admin);
        $this->entityManager->persist($admin);

        $group = new UserGroup();
        $group->setSpace($space);
        $group->setTitle($title);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
        $group->setSlug(('' === $base ? 'group' : $base) . '-' . (++self::$slugCounter));
        foreach ($members as $member) {
            $group->addMember($member);
        }

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $group;
    }

    private static int $slugCounter = 0;
}
