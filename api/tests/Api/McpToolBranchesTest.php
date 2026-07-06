<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\ApiToken;
use App\Entity\MediaObject;
use App\Entity\Board;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Branch coverage for the MCP server that the existing suites
 * ({@see McpTest}, {@see McpReadToolsTest}, {@see McpExtraToolsTest})
 * don't exercise:
 *  - {@see \App\Mcp\Tool\ListTasksTool} filter args (boardId, search,
 *    dueBefore, pagination) + an unreachable boardId.
 *  - {@see \App\Mcp\Tool\UpdateTaskTool} assigneeIds / boardId / tagIds
 *    branches and the entity-validation (422) path.
 *  - {@see \App\Mcp\Tool\DownloadFileTool} forbidden-file + malformed-id
 *    branches.
 *  - {@see \App\Mcp\McpInputHelper} type-coercion edge cases (missing
 *    required arg, wrong JSON type, out-of-range enum).
 *  - {@see \App\Controller\McpController} malformed JSON-RPC envelopes.
 *
 * Test fixtures must be persisted *before* the first call to
 * {@see static::createClient()} — that boots the test kernel and any
 * entities staged through `$this->entityManager` afterwards arrive
 * detached, producing "A new entity was found" cascades.
 */
class McpToolBranchesTest extends ApiTestCase
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

    // ---- ListTasksTool filter branches ----------------------------------

    public function testListTasksFiltersByProjectId(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->makeProject($alice, [$alice], 'Scoped');
        $this->makeTaskInProject($alice, $board, 'In board');
        $this->makeTask($alice, 'Standalone');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_tasks',
            'arguments' => ['boardId' => (string) $board->getId()],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $titles = array_column($items, 'title');
        $this->assertContains('In board', $titles);
        $this->assertNotContains('Standalone', $titles);
    }

    public function testListTasksFiltersBySearch(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->makeTask($alice, 'Quarterly planning meeting');
        $this->makeTask($alice, 'Water the plants');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_tasks',
            'arguments' => ['search' => 'quarterly'],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $titles = array_column($items, 'title');
        $this->assertContains('Quarterly planning meeting', $titles);
        $this->assertNotContains('Water the plants', $titles);
    }

    public function testListTasksFiltersByDueBefore(): void
    {
        $alice = $this->createUser('alice@example.com');
        $soon = $this->makeTask($alice, 'Due soon');
        $soon->setDueDate(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $later = $this->makeTask($alice, 'Due later');
        $later->setDueDate(new \DateTimeImmutable('2027-01-01T00:00:00+00:00'));
        $this->entityManager->flush();
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_tasks',
            'arguments' => ['dueBefore' => '2026-06-01T00:00:00+00:00'],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $titles = array_column($items, 'title');
        $this->assertContains('Due soon', $titles);
        $this->assertNotContains('Due later', $titles);
    }

    public function testListTasksPaginates(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->makeTask($alice, 'First');
        $this->makeTask($alice, 'Second');
        $this->makeTask($alice, 'Third');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_tasks',
            'arguments' => ['page' => 1, 'limit' => 2],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        // The page is clamped to the requested limit even though three exist.
        $this->assertCount(2, $items);
        $this->assertSame(3, $structured['total']);
        $this->assertSame(1, $structured['page']);
        $this->assertSame(2, $structured['limit']);
    }

    public function testListTasksUnreachableProjectReturnsEmpty(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        // A board alice can't see — filtering by it yields no rows (no error).
        $hidden = $this->makeProject($bob, [$bob], 'Hidden');
        $this->makeTask($alice, 'Visible to alice');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_tasks',
            'arguments' => ['boardId' => (string) $hidden->getId()],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertSame([], $items);
        $this->assertSame(0, $structured['total']);
    }

    // ---- UpdateTaskTool branches ----------------------------------------

    public function testUpdateTaskReplacesAssignees(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $board = $this->makeProject($alice, [$alice, $bob], 'Team');
        $task = $this->makeTaskInProject($alice, $board, 'Joint');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => [
                'taskId' => (string) $task->getId(),
                'assigneeIds' => [(string) $bob->getId()],
            ],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $assignees = $structured['assignees'] ?? null;
        $this->assertIsArray($assignees);
        $emails = array_column($assignees, 'email');
        $this->assertContains('bob@example.com', $emails);
    }

    public function testUpdateTaskMovesToProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->makeProject($alice, [$alice], 'Destination');
        $task = $this->makeTask($alice, 'Detached');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => [
                'taskId' => (string) $task->getId(),
                'boardId' => (string) $board->getId(),
            ],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $board = $structured['board'] ?? null;
        $this->assertIsArray($board);
        $this->assertSame('Destination', $board['title']);
    }

    public function testUpdateTaskUnreachableProjectIsError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $hidden = $this->makeProject($bob, [$bob], 'Hidden');
        $task = $this->makeTask($alice, 'Mine');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => [
                'taskId' => (string) $task->getId(),
                'boardId' => (string) $hidden->getId(),
            ],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('not found', $content[0]['text']);
    }

    public function testUpdateTaskInvalidAssigneeFailsValidation(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        // bob is neither the owner nor a member of any shared board, so
        // ValidAssignees rejects him → entity validation (422) branch.
        $task = $this->makeTask($alice, 'Standalone');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => [
                'taskId' => (string) $task->getId(),
                'assigneeIds' => [(string) $bob->getId()],
            ],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('assignees', $content[0]['text']);
    }

    public function testUpdateTaskUnknownTagIsError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Tag me');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        // A well-formed UUID that doesn't resolve to a tag the caller owns.
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => [
                'taskId' => (string) $task->getId(),
                'tagIds' => ['00000000-0000-7000-8000-000000000000'],
            ],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('not found', $content[0]['text']);
    }

    // ---- DownloadFileTool branches --------------------------------------

    public function testDownloadFileForbiddenAttachmentIsError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        // bob's private attachment — alice is neither uploader nor a member
        // of any task/space using it, so canAccess() is false → not found.
        $media = $this->seedAttachment($bob, 'secret.pdf');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'download_file',
            'arguments' => ['fileId' => (string) $media->getId()],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('not found', $content[0]['text']);
    }

    public function testDownloadFileMalformedIdIsValidationError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'download_file',
            'arguments' => ['fileId' => 'not-a-uuid'],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('UUID', $content[0]['text']);
    }

    // ---- McpInputHelper edge cases --------------------------------------

    public function testMissingRequiredArgIsError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        // update_task without taskId → requireUuid sees null → invalid input.
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => ['title' => 'No id supplied'],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('taskId', $content[0]['text']);
    }

    public function testWrongJsonTypeForArrayArgIsError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        // tagIds is a number, not an array → optionalStringArray rejects it.
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_tasks',
            'arguments' => ['tagIds' => 42],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('array', $content[0]['text']);
    }

    public function testOutOfRangeEnumIsError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        // status outside {open, completed} → invalid input branch.
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_tasks',
            'arguments' => ['status' => 'archived'],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('open', $content[0]['text']);
    }

    // ---- McpController malformed-envelope branches ----------------------

    public function testMissingMethodReturnsInvalidRequest(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $client->request('POST', '/mcp', [
            'json' => ['jsonrpc' => '2.0', 'id' => 7],
            'headers' => ['Authorization' => 'Bearer ' . $plain],
        ]);
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $error = $body['error'] ?? null;
        $this->assertIsArray($error);
        $this->assertSame(-32600, $error['code']);
        $this->assertIsString($error['message']);
        $this->assertStringContainsString('method', $error['message']);
    }

    public function testToolsCallWithNonObjectParamsIsError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        // params is a string, not an object → handleToolsCall raises McpException.
        $client->request('POST', '/mcp', [
            'json' => ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => 'oops'],
            'headers' => ['Authorization' => 'Bearer ' . $plain],
        ]);
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $result = $body['result'] ?? null;
        $this->assertIsArray($result);
        $this->assertTrue($result['isError'] ?? null);
        $content = $result['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('object', $content[0]['text']);
    }

    public function testParseErrorOnMalformedJson(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        // Raw, un-parseable body → decode() returns null → -32700 parse error.
        $client->request('POST', '/mcp', [
            'body' => '{ this is not valid json',
            'headers' => [
                'Authorization' => 'Bearer ' . $plain,
                'Content-Type' => 'application/json',
            ],
        ]);
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $error = $body['error'] ?? null;
        $this->assertIsArray($error);
        $this->assertSame(-32700, $error['code']);
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

    private function mintToken(User $user, string $name): string
    {
        $plain = ApiToken::PLAINTEXT_PREFIX . bin2hex(random_bytes(16));
        $token = new ApiToken();
        $token->setUser($user);
        $token->setName($name);
        $token->setTokenHash(hash('sha256', $plain));
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

    private function makeTaskInProject(User $owner, Board $board, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setBoard($board);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
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

    private function seedAttachment(User $owner, string $name): MediaObject
    {
        $media = new MediaObject();
        $media->setOwner($owner);
        $media->setKind(MediaObject::KIND_ATTACHMENT);
        $media->setVariants(['original' => 'attachments/seeded-' . $name]);
        $media->setOriginalName($name);
        $media->setMimeType('application/pdf');
        $media->setByteSize(1024);
        $this->entityManager->persist($media);
        $this->entityManager->flush();
        return $media;
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
