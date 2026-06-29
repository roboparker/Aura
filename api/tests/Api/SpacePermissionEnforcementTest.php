<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\SpaceRole;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Phase 2 enforcement (#space-roles): a member's assigned roles restrict what
 * they can do, while owners/admins keep their powers and zero-role members are
 * unrestricted (Model A — roles fully define a restricted member's access).
 */
class SpacePermissionEnforcementTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        foreach (['Task', 'SpaceRole', 'Project', 'Space', 'User'] as $entity) {
            $this->entityManager->createQuery("DELETE FROM App\\Entity\\$entity")->execute();
        }
    }

    public function testReadOnlyRoleAllowsGetButDeniesWrites(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, $member);
        $project = $this->createProject($admin, $space, 'Backend');
        $this->assignRole($space, $member, ['projects' => ['read' => true]]);

        $client = static::createClient();
        $client->loginUser($member);

        $client->request('GET', '/projects/' . $project->getId());
        $this->assertResponseStatusCodeSame(200);

        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['title' => 'Hijacked'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);

        $client->request('DELETE', '/projects/' . $project->getId());
        $this->assertResponseStatusCodeSame(403);

        $client->request('POST', '/projects', [
            'json' => ['title' => 'New', 'space' => '/spaces/' . $space->getId()],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testNoReadRoleHidesRowsAndItem(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, $member);
        $project = $this->createProject($admin, $space, 'Secret');
        // Role grants nothing for projects → read denied.
        $this->assignRole($space, $member, ['tasks' => ['read' => true]]);

        $client = static::createClient();
        $client->loginUser($member);

        $client->request('GET', '/projects?space=/spaces/' . $space->getId());
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? [];
        $this->assertIsArray($members);
        $this->assertCount(0, $members);

        $client->request('GET', '/projects/' . $project->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testOwnerActsOnOwnContentDespiteRestriction(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, $member);
        $project = $this->createProject($admin, $space, 'Backend');
        $ownTask = $this->createTask($member, $project, 'Mine');
        // Read-only on tasks, but the member owns this task.
        $this->assignRole($space, $member, ['tasks' => ['read' => true]]);

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('PATCH', '/tasks/' . $ownTask->getId(), [
            'json' => ['title' => 'Updated mine'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testZeroRoleMemberIsUnrestricted(): void
    {
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, $member);
        $project = $this->createProject($admin, $space, 'Backend');
        // No role assigned → full access.

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['title' => 'Edited by full-access member'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(200);
    }

    public function testMemberDefaultRoleRestrictsUnassignedMember(): void
    {
        // #space-roles: with a built-in Member role seeded, an unassigned member
        // is governed by it (here read-only) instead of being unrestricted.
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, $member);
        $project = $this->createProject($admin, $space, 'Backend');
        $role = (new SpaceRole())
            ->setSpace($space)
            ->setName('Member')
            ->setBuiltinKey(SpaceRole::BUILTIN_MEMBER)
            ->setPermissions(['projects' => ['read' => true]]);
        $this->entityManager->persist($role);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($member);

        $client->request('GET', '/projects/' . $project->getId());
        $this->assertResponseStatusCodeSame(200);

        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['title' => 'Nope'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testRoleCanGrantBeyondBaseline(): void
    {
        // A role with projects.delete lets a non-creator member delete a
        // project (Model A — roles grant).
        $admin = $this->createUser('admin@example.com');
        $member = $this->createUser('member@example.com');
        $space = $this->createSpace($admin, $member);
        $project = $this->createProject($admin, $space, 'Backend');
        $this->assignRole($space, $member, ['projects' => ['read' => true, 'delete' => true]]);

        $client = static::createClient();
        $client->loginUser($member);
        $client->request('DELETE', '/projects/' . $project->getId());
        $this->assertResponseStatusCodeSame(204);
    }

    private function createSpace(User $admin, ?User $member = null): Space
    {
        $space = (new Space())->setName('Team')->setCreatedBy($admin);
        $this->entityManager->persist($space);
        $adminMembership = (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($adminMembership);
        $this->entityManager->persist($adminMembership);
        if (null !== $member) {
            $memberMembership = (new SpaceMembership())->setUser($member)->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($memberMembership);
            $this->entityManager->persist($memberMembership);
        }
        $this->entityManager->flush();

        return $space;
    }

    private function createProject(User $owner, Space $space, string $title): Project
    {
        $project = (new Project())->setOwner($owner)->setTitle($title)->setSpace($space);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function createTask(User $owner, Project $project, string $title): Task
    {
        $task = (new Task())->setOwner($owner)->setProject($project)->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    /**
     * @param array<string, array<string, bool>> $permissions
     */
    private function assignRole(Space $space, User $user, array $permissions): void
    {
        $role = (new SpaceRole())->setSpace($space)->setName('Restricted')->setPermissions($permissions);
        $this->entityManager->persist($role);
        foreach ($space->getUserMemberships() as $membership) {
            if ($membership->getUser() === $user) {
                $membership->addRole($role);
            }
        }
        $this->entityManager->flush();
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
