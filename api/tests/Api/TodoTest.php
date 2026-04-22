<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Todo;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TodoTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\Todo')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListTodosUnauthenticated(): void
    {
        static::createClient()->request('GET', '/todos');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateTodoUnauthenticated(): void
    {
        static::createClient()->request('POST', '/todos', [
            'json' => ['title' => 'Buy milk'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateTodoAuthenticated(): void
    {
        $user = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/todos', [
            'json' => [
                'title' => 'Buy milk',
                'description' => 'From the store',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Todo',
            'title' => 'Buy milk',
            'description' => 'From the store',
        ]);

        // Owner was set server-side regardless of any supplied value; completedOn
        // is omitted from the JSON-LD response when null, so verify via the entity.
        $todo = $this->reloadTodoByTitle('Buy milk');
        $this->assertSame($user->getId(), $todo->getOwner()?->getId());
        $this->assertNotNull($todo->getCreatedOn());
        $this->assertNull($todo->getCompletedOn());
    }

    public function testCreateTodoIgnoresClientSuppliedOwner(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('POST', '/todos', [
            'json' => [
                'title' => 'Sneaky todo',
                'owner' => '/users/' . $bob->getId(),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);

        $todo = $this->reloadTodoByTitle('Sneaky todo');
        $this->assertSame($alice->getId(), $todo->getOwner()?->getId(), 'Owner must be overwritten by the processor.');
    }

    public function testCreateTodoRequiresTitle(): void
    {
        $user = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/todos', [
            'json' => ['title' => ''],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testListTodosOnlyShowsOwnTodos(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $this->createTodo($alice, 'Alice task 1');
        $this->createTodo($alice, 'Alice task 2');
        $this->createTodo($bob, 'Bob task');

        $client = static::createClient();
        $client->loginUser($alice);

        $client->request('GET', '/todos');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);
    }

    public function testAdminSeesAllTodos(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);

        $this->createTodo($alice, 'A1');
        $this->createTodo($bob, 'B1');
        $this->createTodo($bob, 'B2');

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('GET', '/todos');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 3]);
    }

    public function testGetOwnTodo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $todo = $this->createTodo($alice, 'My task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/todos/' . $todo->getId());

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['title' => 'My task']);
    }

    public function testGetOtherUsersTodoReturns404(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsTodo = $this->createTodo($bob, 'Bob private');

        $client = static::createClient();
        $client->loginUser($alice);
        // Collection extension filters by owner, so an item lookup returns 404
        // rather than leaking its existence with 403.
        $client->request('GET', '/todos/' . $bobsTodo->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCompleteTodo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $todo = $this->createTodo($alice, 'Task to complete');
        $this->assertNull($todo->getCompletedOn());

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/todos/' . $todo->getId(), [
            'json' => ['completedOn' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $reloaded = $this->reloadTodoByTitle('Task to complete');
        $this->assertNotNull($reloaded->getCompletedOn());
        $this->assertTrue($reloaded->isCompleted());
    }

    public function testUncompleteTodo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $todo = $this->createTodo($alice, 'Was done');
        $todo->setCompletedOn(new \DateTimeImmutable());
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/todos/' . $todo->getId(), [
            'json' => ['completedOn' => null],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $reloaded = $this->reloadTodoByTitle('Was done');
        $this->assertNull($reloaded->getCompletedOn());
    }

    public function testDeleteOwnTodo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $todo = $this->createTodo($alice, 'Delete me');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/todos/' . $todo->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testCannotDeleteOtherUsersTodo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsTodo = $this->createTodo($bob, 'Bob owns this');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/todos/' . $bobsTodo->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNewTodoIsPlacedAtTheTop(): void
    {
        $alice = $this->createUser('alice@example.com');
        $existing = $this->createTodo($alice, 'First');
        $existing->setPosition(5);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/todos', [
            'json' => ['title' => 'Newer'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $newer = $this->reloadTodoByTitle('Newer');
        // Position is one slot above the existing minimum so the fresh todo
        // renders at the top without having to shift every row.
        $this->assertSame(4, $newer->getPosition());
    }

    public function testReorderPersistsNewPositions(): void
    {
        $alice = $this->createUser('alice@example.com');
        $a = $this->createTodo($alice, 'A');
        $b = $this->createTodo($alice, 'B');
        $c = $this->createTodo($alice, 'C');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/todos/reorder', [
            'json' => [
                'order' => [
                    '/todos/' . $c->getId(),
                    '/todos/' . $a->getId(),
                    '/todos/' . $b->getId(),
                ],
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(Todo::class);
        $this->assertSame(0, $repo->findOneBy(['title' => 'C'])->getPosition());
        $this->assertSame(1, $repo->findOneBy(['title' => 'A'])->getPosition());
        $this->assertSame(2, $repo->findOneBy(['title' => 'B'])->getPosition());
    }

    public function testReorderReflectsInCollectionOrder(): void
    {
        $alice = $this->createUser('alice@example.com');
        $a = $this->createTodo($alice, 'A');
        $b = $this->createTodo($alice, 'B');
        $c = $this->createTodo($alice, 'C');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/todos/reorder', [
            'json' => [
                'order' => [
                    '/todos/' . $b->getId(),
                    '/todos/' . $c->getId(),
                    '/todos/' . $a->getId(),
                ],
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(204);

        $client->request('GET', '/todos');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse()->toArray();
        $titles = array_map(fn ($t) => $t['title'], $response['member']);
        $this->assertSame(['B', 'C', 'A'], $titles);
    }

    public function testReorderRejectsUnauthenticated(): void
    {
        static::createClient()->request('POST', '/todos/reorder', [
            'json' => ['order' => []],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testReorderRejectsOtherUsersTodo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceTodo = $this->createTodo($alice, 'Alice only');
        $bobsTodo = $this->createTodo($bob, 'Bob owns this');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/todos/reorder', [
            'json' => [
                'order' => [
                    '/todos/' . $aliceTodo->getId(),
                    '/todos/' . $bobsTodo->getId(),
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
        $a = $this->createTodo($alice, 'A');
        $this->createTodo($alice, 'B');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/todos/reorder', [
            'json' => ['order' => ['/todos/' . $a->getId()]],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testReorderRejectsDuplicateIri(): void
    {
        $alice = $this->createUser('alice@example.com');
        $a = $this->createTodo($alice, 'A');
        $this->createTodo($alice, 'B');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/todos/reorder', [
            'json' => [
                'order' => [
                    '/todos/' . $a->getId(),
                    '/todos/' . $a->getId(),
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
        $client->request('POST', '/todos/reorder', [
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
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTodo(User $owner, string $title): Todo
    {
        $todo = new Todo();
        $todo->setOwner($owner);
        $todo->setTitle($title);

        $this->entityManager->persist($todo);
        $this->entityManager->flush();

        return $todo;
    }

    private function reloadTodoByTitle(string $title): Todo
    {
        $this->entityManager->clear();
        $todo = $this->entityManager->getRepository(Todo::class)->findOneBy(['title' => $title]);
        self::assertNotNull($todo, sprintf('Expected to find Todo with title "%s".', $title));
        return $todo;
    }
}
