<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProjectTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // task.project_id, space.created_by_id cascade/SET-NULL via FK,
        // so deleting parents is enough to clean state between tests.
        // Spaces are deleted last because Project.space FK is non-null
        // and CASCADEs from Space — deleting projects first lets Space
        // delete cleanly without dragging unrelated rows.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
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
        $this->assertTrue($user->getId()?->equals($project->getOwner()?->getId()));
        // Project lands in the creator's personal space (via
        // ProjectOwnerProcessor's default), and the creator is the
        // sole admin of that space — so they show up in
        // getEffectiveMembers without any extra wiring.
        $this->assertNotNull($project->getSpace());
        $this->assertCount(1, $project->getEffectiveMembers());
        $this->assertArrayHasKey((string) $user->getId(), $project->getEffectiveMembers());
        $this->assertTrue($project->isSpaceAdmin($user));
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

    public function testListProjectsOnlyShowsSpaceMemberProjects(): void
    {
        // After #185 a project's visibility is determined by membership
        // in its parent space, so the test setup needs three distinct
        // spaces: Alice's private, Bob's private, and a shared space
        // that Bob belongs to. Using `createProject(alice, …, [alice, bob])`
        // would dump everything into Alice's personal space and would
        // implicitly share Alice's "private" project too.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        // Alice's solo project lives in her personal space (Bob never
        // joins it).
        $this->createProject($alice, 'Alice solo', [$alice]);

        // Bob's solo project lives in his personal space.
        $this->createProject($bob, 'Bob solo', [$bob]);

        // Shared project: a fresh shared space with both users as
        // members. Alice creates the project; the helper auto-fills
        // her personal space, so we re-home it onto the shared space
        // and make sure Bob is a member.
        $sharedSpace = (new Space())
            ->setName('Backend Squad')
            ->setCreatedBy($alice);
        $this->entityManager->persist($sharedSpace);
        $this->entityManager->flush();
        $this->ensureSpaceMembership($sharedSpace, $alice, Space::ROLE_ADMIN);
        $this->ensureSpaceMembership($sharedSpace, $bob, Space::ROLE_MEMBER);
        $sharedProject = $this->createProject($alice, 'Shared', [$alice]);
        $sharedProject->setSpace($sharedSpace);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('GET', '/projects');

        $this->assertResponseIsSuccessful();
        // Bob sees his personal project + the shared one, NOT
        // Alice's solo project that lives in her private space.
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

    public function testMemberCanAddAnotherMemberViaProjectMembersEndpoint(): void
    {
        // POST /projects/{id}/members is the existing PWA's seam for
        // adding a teammate. Under the space model (#185) the controller
        // grants space membership rather than project membership; the
        // user shows up in `getEffectiveMembers` immediately.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $project = $this->createProject($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('POST', '/projects/' . $project->getId() . '/members', [
            'json' => ['email' => 'carol@example.com'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Project::class)->find($project->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(3, $reloaded->getEffectiveMembers());
    }

    public function testRegularMemberCannotDeleteProject(): void
    {
        // Per #185: delete requires the project creator OR a space
        // admin. Bob is just a regular space member here, so he gets
        // 403.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($bob);
        $client->request('DELETE', '/projects/' . $project->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testProjectCreatorCanDeleteProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createProject($alice, 'Shared', [$alice, $bob]);

        $client = static::createClient();
        $client->loginUser($alice);
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
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? [];
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
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
        $this->assertNotNull($reloaded);
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
        $this->assertNotNull($task);
        $taskProject = $task->getProject();
        $this->assertNotNull($taskProject);
        $this->assertTrue($project->getId()?->equals($taskProject->getId()));
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
     * Persists a project owned by `$owner` and grants every other
     * `$members` entry direct membership in the project's space (which
     * is `$owner`'s personal space by default — set by
     * ProjectSpaceDefaultListener at PrePersist). The owner is left
     * out of the membership loop because they're already an admin of
     * their personal space from signup.
     *
     * @param User[] $members
     */
    private function createProject(User $owner, string $title, array $members): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        foreach ($members as $member) {
            if ($member === $owner) {
                continue;
            }
            $this->addProjectMember($project, $member);
        }

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
