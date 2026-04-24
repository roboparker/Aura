<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProjectTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        // project_member and task.project_id cascade/null via FK, so deleting
        // the parents is enough to clean state between tests.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListProjectsUnauthenticated(): void
    {
        static::createClient()->request('GET', '/projects');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateProjectAuthenticated(): void
    {
        $user = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/projects', [
            'json' => [
                'title' => 'Launch plan',
                'description' => 'Q3 marketing push',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Project',
            'title' => 'Launch plan',
            'description' => 'Q3 marketing push',
        ]);

        $project = $this->reloadProjectByTitle('Launch plan');
        $this->assertTrue($user->getId()->equals($project->getOwner()?->getId()));
        // Creator is auto-added to members so access checks only need to
        // look at the member set.
        $this->assertCount(1, $project->getMembers());
        $this->assertTrue($user->getId()->equals($project->getMembers()->first()->getId()));
    }

    public function testCreateProjectRequiresTitle(): void
    {
        $user = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/projects', [
            'json' => ['title' => ''],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testListProjectsOnlyShowsMemberProjects(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $this->createProject($alice, 'Alice solo', [$alice]);
        $this->createProject($bob, 'Bob solo', [$bob]);
        $this->createProject($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/projects');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);
    }

    public function testGetOtherUsersProjectReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsProject = $this->createProject($bob, 'Bob private', [$bob]);

        $client = static::createClient();
        $client->loginUser($alice);
        // Mirrors the task extension: 404 rather than 403 so the endpoint
        // doesn't confirm the project exists.
        $client->request('GET', '/projects/' . $bobsProject->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMemberCanUpdateProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => ['description' => 'Updated by non-owner member'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testMemberCanAddAnotherMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $project = $this->createProject($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/projects/' . $project->getId(), [
            'json' => [
                'members' => [
                    '/users/' . $alice->getId(),
                    '/users/' . $bob->getId(),
                    '/users/' . $carol->getId(),
                ],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Project::class)->find($project->getId());
        $this->assertCount(3, $reloaded->getMembers());
    }

    public function testMemberCanDeleteProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('DELETE', '/projects/' . $project->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testMemberSeesTasksFromSharedProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Shared', [$alice, $bob]);

        // Alice's personal task should not leak to Bob; the project task
        // should. This is the key behavior change vs. pre-Projects Tasks.
        $this->createTask($alice, 'Alice personal', null);
        $this->createTask($alice, 'Alice project task', $project);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/tasks');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse()->toArray();
        $titles = array_map(fn ($t) => $t['title'], $response['member']);
        $this->assertSame(['Alice project task'], $titles);
    }

    public function testNonMemberCannotSeeProjectTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $project = $this->createProject($alice, 'Alice+Bob', [$alice, $bob]);
        $task = $this->createTask($alice, 'Private project task', $project);

        $client = static::createClient();
        $client->loginUser($carol);
        $client->request('GET', '/tasks/' . $task->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMemberCanEditProjectTaskOwnedByAnother(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Shared', [$alice, $bob]);
        $task = $this->createTask($alice, 'Alice owns this', $project);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['title' => 'Edited by Bob'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertSame('Edited by Bob', $reloaded->getTitle());
    }

    public function testCreateTaskWithProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Mine', [$alice]);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'In the project',
                'project' => '/projects/' . $project->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);

        $this->entityManager->clear();
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['title' => 'In the project']);
        $this->assertNotNull($task->getProject());
        $this->assertTrue($project->getId()->equals($task->getProject()->getId()));
    }

    public function testPersonalTasksStillOwnerScopedForCreator(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->createTask($alice, 'Alice personal', null);
        $this->createTask($bob, 'Bob personal', null);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 1]);
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
    private function createProject(User $owner, string $title, array $members): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        foreach ($members as $member) {
            $project->addMember($member);
        }

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function createTask(User $owner, string $title, ?Project $project): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        $task->setProject($project);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    private function reloadProjectByTitle(string $title): Project
    {
        $this->entityManager->clear();
        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['title' => $title]);
        self::assertNotNull($project, sprintf('Expected to find Project with title "%s".', $title));
        return $project;
    }
}
