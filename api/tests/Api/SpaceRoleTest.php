<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\SpaceRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Per-space custom roles (#space-roles), Phase 1: space admins define roles
 * (a per-content-type CRUD permission matrix) and assign them to members.
 * Phase 1 is management only — no enforcement yet.
 */
class SpaceRoleTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceRole')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListRequiresAuth(): void
    {
        static::createClient()->request('GET', '/space_roles');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminCanCreateRole(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/space_roles', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'name' => 'Editor',
                'permissions' => ['tasks' => ['read' => true, 'update' => true]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains(['name' => 'Editor']);
    }

    public function testMemberCannotCreateRole(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('POST', '/space_roles', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'name' => 'Sneaky',
                'permissions' => [],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testMemberCanListRolesOfTheirSpace(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $this->seedRole($space, 'Viewer');

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('GET', '/space_roles?space=/spaces/' . $space->getId());
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['member' => [['name' => 'Viewer']]]);
    }

    public function testNonMemberCannotSeeRole(): void
    {
        $admin = $this->createUser('admin@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $space = $this->createSharedSpace($admin);
        $role = $this->seedRole($space, 'Viewer');

        $client = static::createClient();
        $client->loginUser($stranger);
        $client->request('GET', '/space_roles/' . $role->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidPermissionCategoryRejected(): void
    {
        $admin = $this->createUser('admin@example.com');
        $space = $this->createSharedSpace($admin);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('POST', '/space_roles', [
            'json' => [
                'space' => '/spaces/' . $space->getId(),
                'name' => 'Bad',
                'permissions' => ['nonsense' => ['read' => true]],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testAdminCanAssignRolesToMember(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $role = $this->seedRole($space, 'Editor');
        $membership = $this->membershipFor($space, $member);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request(
            'PATCH',
            '/spaces/' . $space->getId() . '/members/' . $membership->getId(),
            [
                'json' => ['roles' => ['/space_roles/' . $role->getId()]],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
            ],
        );
        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(SpaceMembership::class)
            ->find($membership->getId());
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getRoles());
    }

    public function testCannotAssignRoleFromAnotherSpace(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSharedSpace($admin, $member);
        $otherSpace = $this->createSharedSpace($admin);
        $foreignRole = $this->seedRole($otherSpace, 'Foreign');
        $membership = $this->membershipFor($space, $member);

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request(
            'PATCH',
            '/spaces/' . $space->getId() . '/members/' . $membership->getId(),
            [
                'json' => ['roles' => ['/space_roles/' . $foreignRole->getId()]],
                'headers' => ['Content-Type' => 'application/merge-patch+json'],
            ],
        );
        $this->assertResponseStatusCodeSame(422);
    }

    private function createSharedSpace(User $admin, ?User $member = null): Space
    {
        $space = (new Space())->setName('Team')->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $adminMembership = (new SpaceMembership())
            ->setUser($admin)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($adminMembership);
        $this->entityManager->persist($adminMembership);
        if (null !== $member) {
            $memberMembership = (new SpaceMembership())
                ->setUser($member)
                ->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($memberMembership);
            $this->entityManager->persist($memberMembership);
        }
        $this->entityManager->flush();

        return $space;
    }

    private function seedRole(Space $space, string $name): SpaceRole
    {
        $role = (new SpaceRole())
            ->setSpace($space)
            ->setName($name)
            ->setPermissions(['tasks' => ['read' => true]]);
        $this->entityManager->persist($role);
        $this->entityManager->flush();

        return $role;
    }

    private function membershipFor(Space $space, User $user): SpaceMembership
    {
        foreach ($space->getUserMemberships() as $membership) {
            if ($membership->getUser() === $user) {
                return $membership;
            }
        }
        throw new \RuntimeException('No membership for user.');
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
