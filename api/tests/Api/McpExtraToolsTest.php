<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\ApiToken;
use App\Entity\Comment;
use App\Entity\MediaObject;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Coverage for the file-oriented MCP tools (upload_file, list_files,
 * download_file) plus additional update_task / get_my_tasks /
 * list_task_comments branches and validation-error paths. These exercise
 * {@see \App\Mcp\McpInputHelper} and {@see \App\Mcp\McpEntitySerializer}
 * surfaces the other MCP tests don't reach.
 *
 * upload_file round-trips real bytes through ImageUploadService into the
 * `media.storage` filesystem, so download_file can read them straight
 * back — no manual storage wiring needed. list_files is additionally
 * exercised against a Space by attaching a seeded MediaObject via the EM.
 *
 * Test fixtures must be persisted *before* the first call to
 * {@see static::createClient()} — that boots the test kernel and any
 * entities staged through `$this->entityManager` afterwards arrive
 * detached, producing "A new entity was found" cascades.
 */
class McpExtraToolsTest extends ApiTestCase
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

    public function testUploadFileToTaskReturnsMediaObject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Has an attachment');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'upload_file',
            'arguments' => [
                'entityType' => 'task',
                'entityId' => (string) $task->getId(),
                'fileName' => 'notes.txt',
                'content' => base64_encode("Hello from MCP.\n"),
            ],
        ]);

        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('notes.txt', $structured['name']);
        $this->assertSame('attachment', $structured['kind']);
        $this->assertIsString($structured['id']);
        $this->assertNotSame('', $structured['id']);
    }

    public function testUploadFileMissingRequiredArgIsError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Target');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        // Omit the required `content` field to trip McpInputHelper::requireString.
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'upload_file',
            'arguments' => [
                'entityType' => 'task',
                'entityId' => (string) $task->getId(),
                'fileName' => 'notes.txt',
            ],
        ]);

        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('content', $content[0]['text']);
    }

    public function testListFilesOnTaskReturnsUploadedFile(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Files here');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $upload = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'upload_file',
            'arguments' => [
                'entityType' => 'task',
                'entityId' => (string) $task->getId(),
                'fileName' => 'report.txt',
                'content' => base64_encode("body\n"),
            ],
        ]);
        $this->assertFalse($upload['result']['isError'] ?? null);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_files',
            'arguments' => [
                'entityType' => 'task',
                'entityId' => (string) $task->getId(),
            ],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $names = array_column($items, 'name');
        $this->assertContains('report.txt', $names);
    }

    public function testListFilesOnSpaceReturnsSeededAttachment(): void
    {
        $alice = $this->createUser('alice@example.com');
        // A board pins its creator's personal space via the listener.
        $board = $this->makeProject($alice, [$alice], 'Space holder');
        $space = $board->getSpace();
        $this->assertNotNull($space);
        $media = $this->seedAttachment($alice, 'shared.pdf');
        $space->addAttachment($media);
        $this->entityManager->flush();
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_files',
            'arguments' => [
                'entityType' => 'space',
                'entityId' => (string) $space->getId(),
            ],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $names = array_column($items, 'name');
        $this->assertContains('shared.pdf', $names);
    }

    public function testDownloadFileReturnsBase64Content(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Downloadable');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $payload = "Round-trip me.\n";
        $upload = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'upload_file',
            'arguments' => [
                'entityType' => 'task',
                'entityId' => (string) $task->getId(),
                'fileName' => 'rt.txt',
                'content' => base64_encode($payload),
            ],
        ]);
        $this->assertFalse($upload['result']['isError'] ?? null);
        $uploaded = $upload['result']['structuredContent'] ?? null;
        $this->assertIsArray($uploaded);
        $fileId = $uploaded['id'] ?? null;
        $this->assertIsString($fileId);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'download_file',
            'arguments' => ['fileId' => $fileId],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('rt.txt', $structured['name']);
        $returned = $structured['content'] ?? null;
        $this->assertIsString($returned);
        $this->assertSame($payload, base64_decode($returned, true));
    }

    public function testDownloadFileUnknownIdIsError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        // A well-formed but non-existent UUID — exercises the not-found branch.
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'download_file',
            'arguments' => ['fileId' => '00000000-0000-7000-8000-000000000000'],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('not found', $content[0]['text']);
    }

    public function testUpdateTaskSetsAndClearsDueDate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Schedule me');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => [
                'taskId' => (string) $task->getId(),
                'dueDate' => '2026-12-31T09:00:00+00:00',
            ],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $dueDate = $structured['dueDate'] ?? null;
        $this->assertIsString($dueDate);
        $this->assertStringContainsString('2026-12-31', $dueDate);

        // Passing null clears it again.
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => [
                'taskId' => (string) $task->getId(),
                'dueDate' => null,
            ],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertNull($structured['dueDate']);
    }

    public function testGetMyTasksReturnsMultipleAssignedTasks(): void
    {
        $alice = $this->createUser('alice@example.com');
        $first = $this->makeTask($alice, 'First mine');
        $first->addAssignee($alice);
        $second = $this->makeTask($alice, 'Second mine');
        $second->addAssignee($alice);
        $this->makeTask($alice, 'Not assigned');
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
        $this->assertContains('First mine', $titles);
        $this->assertContains('Second mine', $titles);
        $this->assertNotContains('Not assigned', $titles);
    }

    public function testListTaskCommentsReturnsAllComments(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Threaded');
        $this->makeComment($task, $alice, 'Earliest note');
        $this->makeComment($task, $alice, 'Middle note');
        $this->makeComment($task, $alice, 'Latest note');
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
        $this->assertContains('Earliest note', $bodies);
        $this->assertContains('Middle note', $bodies);
        $this->assertContains('Latest note', $bodies);

        // Each comment carries an author summary from McpEntitySerializer.
        $first = $items[0] ?? null;
        $this->assertIsArray($first);
        $author = $first['author'] ?? null;
        $this->assertIsArray($author);
        $this->assertSame('alice@example.com', $author['email']);
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
