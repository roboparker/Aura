<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\ApiToken;
use App\Entity\Comment;
use App\Entity\Board;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Additional coverage for the under-tested read-oriented MCP tools
 * (list_tasks, get_my_tasks, search_tasks, list_task_comments,
 * get_board, get_custom_fields) plus extra update_task branches.
 * Drives each via the JSON-RPC `tools/call` endpoint and asserts the
 * structured response shape.
 *
 * Test fixtures must be persisted *before* the first call to
 * {@see static::createClient()} — that boots the test kernel and any
 * entities you've staged through `$this->entityManager` afterwards
 * arrive detached, producing "A new entity was found" cascades.
 */
class McpReadToolsTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\ApiToken')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListTasksReturnsItemsAndFilters(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->makeTask($alice, 'Open task');
        $done = $this->makeTask($alice, 'Done task');
        $done->setCompletedOn(new \DateTimeImmutable());
        $this->entityManager->flush();
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_tasks',
            'arguments' => [],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $titles = array_column($items, 'title');
        $this->assertContains('Open task', $titles);
        $this->assertContains('Done task', $titles);

        // `status=open` narrows to the unfinished task only.
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_tasks',
            'arguments' => ['status' => 'open'],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $titles = array_column($items, 'title');
        $this->assertContains('Open task', $titles);
        $this->assertNotContains('Done task', $titles);
    }

    public function testGetMyTasksReturnsAssignedItems(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Assigned to me');
        $task->addAssignee($alice);
        $this->entityManager->flush();
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_my_tasks',
            'arguments' => [],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $titles = array_column($items, 'title');
        $this->assertContains('Assigned to me', $titles);
    }

    public function testSearchTasksFindsByQuery(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->makeTask($alice, 'A distinctiveword appears here');
        $this->makeTask($alice, 'Unrelated chore');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'search_tasks',
            'arguments' => ['query' => 'distinctiveword'],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $titles = array_column($items, 'title');
        $this->assertContains('A distinctiveword appears here', $titles);
        $this->assertNotContains('Unrelated chore', $titles);
    }

    public function testListTaskCommentsReturnsComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Has comments');
        $this->makeComment($task, $alice, 'First comment body');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_task_comments',
            'arguments' => ['taskId' => (string) $task->getId()],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $bodies = array_column($items, 'body');
        $this->assertContains('First comment body', $bodies);
    }

    public function testGetProjectReturnsTitle(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->makeProject($alice, [$alice], 'Roadmap');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_board',
            'arguments' => ['boardId' => (string) $board->getId()],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('Roadmap', $structured['title']);
    }

    public function testGetCustomFieldsReturnsShape(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->makeProject($alice, [$alice], 'With fields');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_custom_fields',
            'arguments' => ['boardId' => (string) $board->getId()],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
    }

    public function testUpdateTaskTitleAndDescription(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Old title');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => [
                'taskId' => (string) $task->getId(),
                'title' => 'New title',
                'description' => 'A fresh description',
            ],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('New title', $structured['title']);
        $this->assertSame('A fresh description', $structured['description']);
    }

    /**
     * Returns the JSON-RPC response body. Every site that uses this
     * helper goes through {@see assertResponseIsSuccessful()} and
     * checks a `result`, so the shape is widened to `array<string, mixed>`
     * on result (deep nesting is narrowed per-callsite with explicit
     * `assertIsArray()` / `assertArrayHasKey()` calls).
     *
     * @param array<string, mixed> $params
     * @return array{
     *   jsonrpc: string,
     *   id?: int|string,
     *   result: array<string, mixed>,
     *   error?: array{code: int, message: string, data?: mixed}
     * }
     */
    private function callMcp(Client $client, string $bearer, string $method, array $params = []): array
    {
        $client->request('POST', '/mcp', [
            'json' => [
                'jsonrpc' => '2.0',
                'id' => mt_rand(1, 99999),
                'method' => $method,
                'params' => $params,
            ],
            'headers' => ['Authorization' => 'Bearer ' . $bearer],
        ]);
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        /**
         * @var array{
         *   jsonrpc: string,
         *   id?: int|string,
         *   result: array<string, mixed>,
         *   error?: array{code: int, message: string, data?: mixed}
         * } $body
         */
        $body = $response->toArray();
        return $body;
    }

    /**
     * @param array<string, mixed>|null $accessPolicy
     */
    private function mintToken(
        User $user,
        string $name,
        ?\DateTimeImmutable $expiresAt = null,
        ?array $accessPolicy = null,
    ): string {
        $plain = ApiToken::PLAINTEXT_PREFIX . bin2hex(random_bytes(16));
        $token = new ApiToken();
        $token->setUser($user);
        $token->setName($name);
        $token->setTokenHash(hash('sha256', $plain));
        $token->setExpiresAt($expiresAt);
        $token->setAccessPolicy($accessPolicy);
        $this->entityManager->persist($token);
        $this->entityManager->flush();
        return $plain;
    }

    private function makeTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    private function makeComment(Task $task, User $author, string $body): Comment
    {
        $comment = new Comment();
        $comment->setTask($task);
        $comment->setAuthor($author);
        $comment->setBody($body);
        $this->entityManager->persist($comment);
        $this->entityManager->flush();
        return $comment;
    }

    /**
     * @param User[] $members
     */
    private function makeProject(User $owner, array $members, string $title): Board
    {
        $board = new Board();
        $board->setOwner($owner);
        $board->setTitle($title);
        foreach ($members as $member) {
            $this->addBoardMember($board, $member);
        }
        $this->entityManager->persist($board);
        $this->entityManager->flush();
        return $board;
    }

    private function createUser(string $email): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

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
