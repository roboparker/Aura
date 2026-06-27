<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Groups are owned by a single space (#groups-space): any member of the
 * group's space can create / edit / delete / manage it, and a non-member
 * gets the existence-hiding 404. There is no per-group owner, no transfer,
 * and delete is not step-up protected.
 */
class UserGroupTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // Children first, then spaces, then users (FK CASCADE handles the rest).
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserGroup')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
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
        $space = $this->createSpace($user, 'Alice space');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/groups', [
            'json' => [
                'title' => 'Backend team',
                'description' => 'PHP folks',
                'space' => '/spaces/' . $space->getId(),
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
        $this->assertTrue($space->getId()?->equals($group->getSpace()?->getId()));
        // Creator is auto-added to the member set on create.
        $this->assertCount(1, $group->getMembers());
        $firstMember = $group->getMembers()->first();
        self::assertNotFalse($firstMember);
        $this->assertTrue($user->getId()?->equals($firstMember->getId()));
    }

    public function testCreateGroupInForeignSpaceForbidden(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobSpace = $this->createSpace($bob, 'Bob space');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/groups', [
            'json' => [
                'title' => 'Sneaky',
                'space' => '/spaces/' . $bobSpace->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        // Alice can't see Bob's space, so the IRI doesn't resolve (400) —
        // existence-hiding before the securityPostDenormalize membership gate.
        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateGroupRequiresTitle(): void
    {
        $user = $this->createUser('alice@example.com');
        $space = $this->createSpace($user, 'Alice space');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/groups', [
            'json' => ['title' => '', 'space' => '/spaces/' . $space->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testListGroupsOnlyShowsGroupsInMySpaces(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $aliceSpace = $this->createSpace($alice, 'Alice space');
        $bobSpace = $this->createSpace($bob, 'Bob space');
        $shared = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($shared, $bob);

        $this->createGroup($aliceSpace, 'Alice solo', [$alice]);
        $this->createGroup($bobSpace, 'Bob solo', [$bob]);
        $this->createGroup($shared, 'Shared group', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/groups');

        $this->assertResponseIsSuccessful();
        // Bob belongs to Bob space + Shared → 2 groups.
        $this->assertJsonContains(['totalItems' => 2]);
    }

    public function testGetGroupInForeignSpaceReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobSpace = $this->createSpace($bob, 'Bob space');
        $bobsGroup = $this->createGroup($bobSpace, 'Bob private', [$bob]);

        $client = static::createClient();
        $client->loginUser($alice);
        // 404 rather than 403 — matches existence-hiding used elsewhere.
        $client->request('GET', '/groups/' . $bobsGroup->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonSpaceMemberCannotPatch(): void
    {
        $alice = $this->createUser('alice@example.com');
        $carol = $this->createUser('carol@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $group = $this->createGroup($space, 'Group', [$alice]);

        $client = static::createClient();
        $client->loginUser($carol);
        $client->request('PATCH', '/groups/' . $group->getId(), [
            'json' => ['description' => 'Carol trying to edit'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        // Carol can't even see the group → 404.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSpaceMemberCanPatch(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($space, $bob);
        $group = $this->createGroup($space, 'Group', [$alice]);

        $client = static::createClient();
        // Bob is a space member but not in the group — he can still edit it.
        $client->loginUser($bob);
        $client->request('PATCH', '/groups/' . $group->getId(), [
            'json' => ['description' => 'Member edit'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testNonSpaceMemberCannotDelete(): void
    {
        $alice = $this->createUser('alice@example.com');
        $carol = $this->createUser('carol@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $group = $this->createGroup($space, 'Group', [$alice]);

        $client = static::createClient();
        $client->loginUser($carol);
        $client->request('DELETE', '/groups/' . $group->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testSpaceMemberCanDelete(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $group = $this->createGroup($space, 'Solo', [$alice]);

        $client = static::createClient();
        $client->loginUser($alice);
        // No step-up — a plain DELETE removes it.
        $client->request('DELETE', '/groups/' . $group->getId());

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $this->assertNull(
            $this->entityManager->getRepository(UserGroup::class)->find($group->getId()),
        );
    }

    public function testSpaceMemberCanAddMemberByEmail(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $group = $this->createGroup($space, 'Solo', [$alice]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'bob@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(2, $reloaded->getMembers());
    }

    public function testNonSpaceMemberAddingMemberSees404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $group = $this->createGroup($space, 'Alice only', [$alice]);

        $client = static::createClient();
        $client->loginUser($carol);
        $client->request('POST', '/groups/' . $group->getId() . '/members', [
            'json' => ['email' => 'bob@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testSpaceMemberCanRemoveMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $group = $this->createGroup($space, 'Three', [$alice, $bob, $carol]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/groups/' . $group->getId() . '/members/' . $bob->getId());

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(2, $reloaded->getMembers());
    }

    public function testNonSpaceMemberCannotRemoveMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $space = $this->createSpace($alice, 'Alice space');
        $group = $this->createGroup($space, 'Three', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($carol);
        $client->request('DELETE', '/groups/' . $group->getId() . '/members/' . $bob->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMemberCanLeaveGroup(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->createSpace($alice, 'Shared');
        $this->ensureSpaceMembership($space, $bob);
        $group = $this->createGroup($space, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/groups/' . $group->getId() . '/leave');

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(1, $reloaded->getMembers());
    }

    public function testCreateGroupGeneratesSlug(): void
    {
        $user = $this->createUser('alice@example.com');
        $space = $this->createSpace($user, 'Alice space');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/groups', [
            'json' => ['title' => 'Creative Team', 'space' => '/spaces/' . $space->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains(['slug' => 'creative-team']);
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

    private function createSpace(User $owner, string $name): Space
    {
        $space = new Space();
        $space->setName($name);
        $space->setCreatedBy($owner);
        $this->entityManager->persist($space);

        $admin = (new SpaceMembership())
            ->setUser($owner)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($admin);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $space;
    }

    private function ensureSpaceMembership(
        Space $space,
        User $user,
        string $role = Space::ROLE_MEMBER,
    ): void {
        $membership = (new SpaceMembership())
            ->setUser($user)
            ->setRole($role);
        $space->addUserMembership($membership);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();
    }

    /**
     * @param User[] $members
     */
    private function createGroup(Space $space, string $title, array $members): UserGroup
    {
        $group = new UserGroup();
        $group->setSpace($space);
        $group->setTitle($title);
        // Direct persist bypasses UserGroupOwnerProcessor, so set a slug
        // by hand (the column is NOT NULL). Titles are distinct within a
        // test, so a slugified title is collision-free here.
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
        $group->setSlug('' === $slug ? 'group' : $slug);
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
