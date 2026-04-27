<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserGroupTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\UserGroup')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListGroupsUnauthenticated(): void
    {
        static::createClient()->request('GET', '/groups');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateGroupAuthenticated(): void
    {
        $user = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/groups', [
            'json' => [
                'title' => 'Backend team',
                'description' => 'PHP folks',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Group',
            'title' => 'Backend team',
            'description' => 'PHP folks',
        ]);

        $group = $this->reloadGroupByTitle('Backend team');
        $this->assertTrue($user->getId()->equals($group->getOwner()?->getId()));
        // Creator is auto-added to the member set on create.
        $this->assertCount(1, $group->getMembers());
        $this->assertTrue($user->getId()->equals($group->getMembers()->first()->getId()));
    }

    public function testCreateGroupRequiresTitle(): void
    {
        $user = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/groups', [
            'json' => ['title' => ''],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testListGroupsOnlyShowsOwnedOrMemberGroups(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $this->createGroup($alice, 'Alice solo', [$alice]);
        $this->createGroup($bob, 'Bob solo', [$bob]);
        $this->createGroup($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);
    }

    public function testGetOtherUsersGroupReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsGroup = $this->createGroup($bob, 'Bob private', [$bob]);

        $client = static::createClient();
        $client->loginUser($alice);
        // 404 rather than 403 — matches existence-hiding used elsewhere.
        $client->request('GET', '/groups/' . $bobsGroup->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonOwnerMemberCannotPatch(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $group = $this->createGroup($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/groups/' . $group->getId(), [
            'json' => ['description' => 'Bob trying to edit'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanPatch(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $group = $this->createGroup($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/groups/' . $group->getId(), [
            'json' => ['description' => 'Owner edit'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testNonOwnerMemberCannotDelete(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $group = $this->createGroup($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('DELETE', '/groups/' . $group->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanDelete(): void
    {
        $alice = $this->createUser('alice@example.com');
        $group = $this->createGroup($alice, 'Solo', [$alice]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/groups/' . $group->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testOwnerCanTransferOwnership(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $group = $this->createGroup($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/groups/' . $group->getId(), [
            'json' => ['owner' => '/users/' . $bob->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $this->assertTrue($bob->getId()->equals($reloaded->getOwner()?->getId()));
    }

    public function testNonOwnerCannotTransferOwnership(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $group = $this->createGroup($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/groups/' . $group->getId(), [
            'json' => ['owner' => '/users/' . $bob->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanAddMemberByEmail(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $group = $this->createGroup($alice, 'Solo', [$alice]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'bob@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $this->assertCount(2, $reloaded->getMembers());
    }

    public function testNonOwnerMemberCannotAddMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $group = $this->createGroup($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'carol@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNonMemberAddingMemberSees404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $group = $this->createGroup($alice, 'Alice only', [$alice]);

        $client = static::createClient();
        $client->loginUser($carol);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'bob@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testOwnerCanRemoveMemberViaPatch(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $group = $this->createGroup($alice, 'Three', [$alice, $bob, $carol]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/groups/' . $group->getId(), [
            'json' => [
                'members' => [
                    '/users/' . $alice->getId(),
                    '/users/' . $carol->getId(),
                ],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $this->assertCount(2, $reloaded->getMembers());
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

    private function reloadGroupByTitle(string $title): UserGroup
    {
        $this->entityManager->clear();
        $group = $this->entityManager->getRepository(UserGroup::class)->findOneBy(['title' => $title]);
        self::assertNotNull($group, sprintf('Expected to find UserGroup with title "%s".', $title));
        return $group;
    }
}
