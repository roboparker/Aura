<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\ApiToken;
use App\Entity\Comment;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end coverage for the MCP server (#92): protocol handshake,
 * Bearer auth, and a representative tool call from each surface
 * (task / project / assignment / comment).
 *
 * Test fixtures must be persisted *before* the first call to
 * {@see static::createClient()} — that boots the test kernel and any
 * entities you've staged through `$this->entityManager` afterwards
 * arrive detached, producing "A new entity was found" cascades.
 */
class McpTest extends ApiTestCase
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
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRejectsWithoutBearerToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/mcp', [
            'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testRejectsInvalidBearerToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/mcp', [
            'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
            'headers' => ['Authorization' => 'Bearer aura_pat_not-a-real-token'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testRejectsExpiredToken(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'Expired', expiresAt: new \DateTimeImmutable('-1 day'));

        $client = static::createClient();
        $client->request('POST', '/mcp', [
            'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
            'headers' => ['Authorization' => 'Bearer ' . $plain],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testInitializeReturnsCapabilities(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'initialize', ['protocolVersion' => '2024-11-05']);

        $this->assertSame('2.0', $body['jsonrpc']);
        $serverInfo = $body['result']['serverInfo'] ?? null;
        $this->assertIsArray($serverInfo);
        $this->assertSame('aura-mcp', $serverInfo['name']);
        $capabilities = $body['result']['capabilities'] ?? null;
        $this->assertIsArray($capabilities);
        $this->assertArrayHasKey('tools', $capabilities);
    }

    public function testToolsListIncludesAllCategories(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/list');

        $tools = $body['result']['tools'] ?? null;
        $this->assertIsArray($tools);
        $names = array_column($tools, 'name');
        foreach (
            [
            'create_task', 'get_task', 'update_task', 'delete_task', 'list_tasks', 'search_tasks',
            'create_project', 'get_project', 'update_project', 'delete_project', 'list_projects',
            'assign_task', 'unassign_task', 'get_my_tasks',
            'add_task_comment', 'list_task_comments',
            'upload_file', 'list_files', 'download_file',
            'get_custom_fields',
            ] as $expected
        ) {
            $this->assertContains($expected, $names, sprintf('Missing tool "%s"', $expected));
        }
    }

    public function testCreateTaskTool(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'create_task',
            'arguments' => ['title' => 'From MCP', 'description' => 'Hello'],
        ]);

        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('From MCP', $structured['title']);
        $owner = $structured['owner'];
        $this->assertIsArray($owner);
        $this->assertSame('alice@example.com', $owner['email']);
        $this->assertSame('open', $structured['status']);
    }

    public function testGetTaskUnreachableTaskReturnsToolError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsTask = $this->makeTask($bob, 'Private');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_task',
            'arguments' => ['taskId' => (string) $bobsTask->getId()],
        ]);

        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('not found', $content[0]['text']);
    }

    public function testUpdateTaskCompletes(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Buy milk');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => ['taskId' => (string) $task->getId(), 'status' => 'completed'],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('completed', $structured['status']);
    }

    public function testListProjectsHonoursMembership(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->makeProject($alice, [$alice], 'Mine');
        $this->makeProject($bob, [$bob], 'Hidden');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_projects',
            'arguments' => [],
        ]);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $titles = array_column($items, 'title');
        $this->assertSame(['Mine'], $titles);
    }

    public function testAssignTaskToProjectMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->makeProject($alice, [$alice, $bob], 'Team');
        $task = $this->makeTaskInProject($alice, $project, 'Joint work');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'assign_task',
            'arguments' => ['taskId' => (string) $task->getId(), 'userId' => (string) $bob->getId()],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $assignees = $structured['assignees'] ?? null;
        $this->assertIsArray($assignees);
        $emails = array_column($assignees, 'email');
        $this->assertContains('bob@example.com', $emails);
    }

    public function testAddCommentTool(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Discuss');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'add_task_comment',
            'arguments' => ['taskId' => (string) $task->getId(), 'body' => 'Looks good!'],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('Looks good!', $structured['body']);
        $author = $structured['author'];
        $this->assertIsArray($author);
        $this->assertSame('alice@example.com', $author['email']);
    }

    public function testInvalidUuidProducesValidationError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_task',
            'arguments' => ['taskId' => 'not-a-uuid'],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('UUID', $content[0]['text']);
    }

    public function testUnknownToolReturnsToolError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'no_such_tool',
            'arguments' => [],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
    }

    public function testNotificationProduces202(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $client->request('POST', '/mcp', [
            'json' => ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            'headers' => ['Authorization' => 'Bearer ' . $plain],
        ]);
        $this->assertResponseStatusCodeSame(202);
    }

    public function testTokenScopesEnforceAllowList(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Read me');
        $plain = $this->mintToken($alice, 'Read-only', scopes: ['read:tasks']);

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_task',
            'arguments' => ['taskId' => (string) $task->getId()],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'create_task',
            'arguments' => ['title' => 'Forbidden'],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
        $content = $body['result']['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);
        $this->assertStringContainsString('scopes', $content[0]['text']);
    }

    public function testEveryRegisteredToolHasAScope(): void
    {
        // Guards against a new MCP tool silently defaulting to "allowed for
        // any scope" — every tool must be mapped in ScopeMap.
        $registry = static::getContainer()->get(\App\Mcp\McpToolRegistry::class);
        $this->assertInstanceOf(\App\Mcp\McpToolRegistry::class, $registry);
        foreach ($registry->all() as $tool) {
            $name = $tool->getName();
            $this->assertNotNull(
                \App\Mcp\ScopeMap::requiredScope($name),
                sprintf('MCP tool "%s" has no scope mapping in ScopeMap.', $name),
            );
        }
    }

    public function testTokenLastUsedIsStamped(): void
    {
        $alice = $this->createUser('alice@example.com');
        $plain = $this->mintToken($alice, 'Tracked');

        $client = static::createClient();
        $this->callMcp($client, $plain, 'tools/list');

        // Re-fetch via the kernel's EM so we don't see a stale managed copy.
        $repo = static::getContainer()->get('doctrine')->getManager()->getRepository(ApiToken::class);
        $token = $repo->findOneBy(['name' => 'Tracked']);
        $this->assertNotNull($token);
        $this->assertNotNull($token->getLastUsedAt());
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
     * @param list<string> $scopes
     */
    private function mintToken(User $user, string $name, ?\DateTimeImmutable $expiresAt = null, array $scopes = []): string
    {
        $plain = ApiToken::PLAINTEXT_PREFIX . bin2hex(random_bytes(16));
        $token = new ApiToken();
        $token->setUser($user);
        $token->setName($name);
        $token->setTokenHash(hash('sha256', $plain));
        $token->setExpiresAt($expiresAt);
        $token->setScopes($scopes);
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

    private function makeTaskInProject(User $owner, Project $project, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setProject($project);
        $task->setTitle($title);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    /**
     * @param User[] $members
     */
    private function makeProject(User $owner, array $members, string $title): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        foreach ($members as $member) {
            $this->addProjectMember($project, $member);
        }
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
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
