<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TaskTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListTasksUnauthenticated(): void
    {
        static::createClient()->request('GET', '/tasks');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateTaskUnauthenticated(): void
    {
        static::createClient()->request('POST', '/tasks', [
            'json' => ['title' => 'Buy milk'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateTaskAuthenticated(): void
    {
        $user = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Buy milk',
                'description' => 'From the store',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Task',
            'title' => 'Buy milk',
            'description' => 'From the store',
        ]);

        // Owner was set server-side regardless of any supplied value; completedOn
        // is omitted from the JSON-LD response when null, so verify via the entity.
        $task = $this->reloadTaskByTitle('Buy milk');
        $this->assertTrue($user->getId()->equals($task->getOwner()?->getId()));
        $this->assertNotNull($task->getCreatedOn());
        $this->assertNull($task->getCompletedOn());
    }

    public function testCreateTaskIgnoresClientSuppliedOwner(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Sneaky task',
                'owner' => '/users/' . $bob->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);

        $task = $this->reloadTaskByTitle('Sneaky task');
        $this->assertTrue($alice->getId()->equals($task->getOwner()?->getId()), 'Owner must be overwritten by the processor.');
    }

    public function testCreateTaskRequiresTitle(): void
    {
        $user = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/tasks', [
            'json' => ['title' => ''],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testListTasksOnlyShowsOwnTasks(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $this->createTask($alice, 'Alice task 1');
        $this->createTask($alice, 'Alice task 2');
        $this->createTask($bob, 'Bob task');

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('GET', '/tasks');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);
    }

    public function testAdminSeesAllTasks(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);

        $this->createTask($alice, 'A1');
        $this->createTask($bob, 'B1');
        $this->createTask($bob, 'B2');

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('GET', '/tasks');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 3]);
    }

    public function testGetOwnTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'My task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks/' . $task->getId());

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['title' => 'My task']);
    }

    public function testGetOtherUsersTaskReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsTask = $this->createTask($bob, 'Bob private');

        $client = static::createClient();
        $client->loginUser($alice);
        // Collection extension filters by owner, so an item lookup returns 404
        // rather than leaking its existence with 403.
        $client->request('GET', '/tasks/' . $bobsTask->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCompleteTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Task to complete');
        $this->assertNull($task->getCompletedOn());

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $reloaded = $this->reloadTaskByTitle('Task to complete');
        $this->assertNotNull($reloaded->getCompletedOn());
        $this->assertTrue($reloaded->isCompleted());
    }

    public function testUncompleteTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Was done');
        $task->setCompletedOn(new \DateTimeImmutable());
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => null],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $reloaded = $this->reloadTaskByTitle('Was done');
        $this->assertNull($reloaded->getCompletedOn());
    }

    public function testDeleteOwnTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Delete me');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/tasks/' . $task->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testCannotDeleteOtherUsersTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsTask = $this->createTask($bob, 'Bob owns this');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/tasks/' . $bobsTask->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNewTaskIsPlacedAtTheTop(): void
    {
        $alice = $this->createUser('alice@example.com');
        $existing = $this->createTask($alice, 'First');
        $existing->setPosition(5);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Newer'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $newer = $this->reloadTaskByTitle('Newer');
        // Position is one slot above the existing minimum so the fresh task
        // renders at the top without having to shift every row.
        $this->assertSame(4, $newer->getPosition());
    }

    public function testReorderPersistsNewPositions(): void
    {
        $alice = $this->createUser('alice@example.com');
        $a = $this->createTask($alice, 'A');
        $b = $this->createTask($alice, 'B');
        $c = $this->createTask($alice, 'C');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/reorder', [
            'json' => [
                'order' => [
                    '/tasks/' . $c->getId(),
                    '/tasks/' . $a->getId(),
                    '/tasks/' . $b->getId(),
                ],
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(Task::class);
        $this->assertSame(0, $repo->findOneBy(['title' => 'C'])->getPosition());
        $this->assertSame(1, $repo->findOneBy(['title' => 'A'])->getPosition());
        $this->assertSame(2, $repo->findOneBy(['title' => 'B'])->getPosition());
    }

    public function testReorderReflectsInCollectionOrder(): void
    {
        $alice = $this->createUser('alice@example.com');
        $a = $this->createTask($alice, 'A');
        $b = $this->createTask($alice, 'B');
        $c = $this->createTask($alice, 'C');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/reorder', [
            'json' => [
                'order' => [
                    '/tasks/' . $b->getId(),
                    '/tasks/' . $c->getId(),
                    '/tasks/' . $a->getId(),
                ],
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(204);

        $client->request('GET', '/tasks');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse()->toArray();
        $titles = array_map(fn ($t) => $t['title'], $response['member']);
        $this->assertSame(['B', 'C', 'A'], $titles);
    }

    public function testReorderRejectsUnauthenticated(): void
    {
        static::createClient()->request('POST', '/tasks/reorder', [
            'json' => ['order' => []],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testReorderRejectsOtherUsersTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceTask = $this->createTask($alice, 'Alice only');
        $bobsTask = $this->createTask($bob, 'Bob owns this');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/reorder', [
            'json' => [
                'order' => [
                    '/tasks/' . $aliceTask->getId(),
                    '/tasks/' . $bobsTask->getId(),
                ],
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        // 404 rather than 403 to avoid leaking existence, matching the
        // item-lookup behavior of the owner query extension.
        $this->assertResponseStatusCodeSame(404);
    }

    public function testReorderRejectsIncompleteOrder(): void
    {
        $alice = $this->createUser('alice@example.com');
        $a = $this->createTask($alice, 'A');
        $this->createTask($alice, 'B');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/reorder', [
            'json' => ['order' => ['/tasks/' . $a->getId()]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testReorderRejectsDuplicateIri(): void
    {
        $alice = $this->createUser('alice@example.com');
        $a = $this->createTask($alice, 'A');
        $this->createTask($alice, 'B');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/reorder', [
            'json' => [
                'order' => [
                    '/tasks/' . $a->getId(),
                    '/tasks/' . $a->getId(),
                ],
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testReorderRejectsMalformedBody(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/reorder', [
            'json' => ['unexpected' => 'shape'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
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

    private function createTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    private function reloadTaskByTitle(string $title): Task
    {
        $this->entityManager->clear();
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['title' => $title]);
        self::assertNotNull($task, sprintf('Expected to find Task with title "%s".', $title));
        return $task;
    }
}
