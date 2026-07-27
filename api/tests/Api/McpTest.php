<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\ApiToken;
use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\Page;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\SpaceRole;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end coverage for the MCP server (#92): protocol handshake,
 * Bearer auth, and a representative tool call from each surface
 * (task / board / assignment / comment).
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

        // Delete child-to-parent so DQL bulk deletes don't trip FKs.
        $this->entityManager->createQuery('DELETE FROM App\Entity\ApiToken')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldValue')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CustomFieldDefinition')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Page')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Board')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Tag')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserGroupMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\UserGroup')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
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
            'headers' => ['Authorization' => 'Bearer madori_pat_not-a-real-token'],
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
        $this->assertSame('madori-mcp', $serverInfo['name']);
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
            'create_board', 'get_board', 'update_board', 'delete_board', 'list_boards',
            'assign_task', 'unassign_task', 'get_my_tasks',
            'add_task_comment', 'list_task_comments',
            'upload_file', 'list_files', 'download_file',
            'get_custom_fields',
            'list_spaces',
            'create_page', 'get_page', 'update_page', 'delete_page', 'list_pages',
            'list_tags', 'create_tag',
            'add_page_comment', 'list_page_comments',
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
            'name' => 'list_boards',
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
        $board = $this->makeProject($alice, [$alice, $bob], 'Team');
        $task = $this->makeTaskInProject($alice, $board, 'Joint work');
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

    public function testListSpacesReturnsMembershipsWithRole(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->makeSpace($alice, 'Alice Space');
        $this->makeSpace($bob, 'Bob Space');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_spaces',
            'arguments' => [],
        ]);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $names = array_column($items, 'name');
        $this->assertSame(['Alice Space'], $names);
        $first = $items[0];
        $this->assertIsArray($first);
        $this->assertSame('admin', $first['role']);
        $this->assertSame((string) $space->getId(), $first['id']);
    }

    public function testCreateAndGetPageInSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->makeSpace($alice);
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'create_page',
            'arguments' => [
                'title' => 'Onboarding',
                'body' => '# Welcome',
                'spaceId' => (string) $space->getId(),
            ],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('Onboarding', $structured['title']);
        $this->assertSame((string) $space->getId(), $structured['spaceId']);
        $pageId = $structured['id'];
        $this->assertIsString($pageId);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_page',
            'arguments' => ['pageId' => $pageId],
        ]);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('# Welcome', $structured['body']);
    }

    public function testCreatePageDefaultsToPersonalSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        // Provision Alice's personal space the way the app does on signup
        // — persisting a board triggers BoardSpaceDefaultListener.
        $this->makeProject($alice, [$alice], 'Seed');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'create_page',
            'arguments' => ['title' => 'Personal note'],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('Personal note', $structured['title']);
        $this->assertIsString($structured['spaceId']);
    }

    public function testListPagesHonoursSpaceMembership(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceSpace = $this->makeSpace($alice, 'Alice Space');
        $bobSpace = $this->makeSpace($bob, 'Bob Space');
        $this->makePage($alice, $aliceSpace, 'Mine');
        $this->makePage($bob, $bobSpace, 'Hidden');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_pages',
            'arguments' => [],
        ]);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertSame(['Mine'], array_column($items, 'title'));
    }

    public function testUpdateAndDeletePage(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->makeSpace($alice);
        $page = $this->makePage($alice, $space, 'Draft');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_page',
            'arguments' => ['pageId' => (string) $page->getId(), 'title' => 'Final'],
        ]);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('Final', $structured['title']);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'delete_page',
            'arguments' => ['pageId' => (string) $page->getId()],
        ]);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertTrue($structured['deleted']);
        $this->assertNull($this->entityManager->getRepository(Page::class)->find($page->getId()));
    }

    public function testPageEditByNonAuthorNonAdminIsForbidden(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $space = $this->makeSpace($alice);
        // Bob is a member with a role that lacks pages.update (#space-roles) —
        // a plain no-role member would have full access.
        $membership = $this->ensureSpaceMembership($space, $bob, Space::ROLE_MEMBER);
        $role = (new SpaceRole())
            ->setSpace($space)
            ->setName('Restricted')
            ->setPermissions(['pages' => ['read' => true]]);
        $this->entityManager->persist($role);
        $membership->addRole($role);
        $this->entityManager->flush();
        $page = $this->makePage($alice, $space, 'Alice doc');
        $plain = $this->mintToken($bob, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_page',
            'arguments' => ['pageId' => (string) $page->getId(), 'title' => 'Hijacked'],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
    }

    public function testCreateAndListTags(): void
    {
        $alice = $this->createUser('alice@example.com');
        // Provision Alice's personal space (create_tag defaults to it) the way
        // the app does on signup — persisting a board triggers the listener.
        $this->makeProject($alice, [$alice], 'Seed');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'create_tag',
            'arguments' => ['title' => 'urgent', 'color' => '#b91c1c'],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('urgent', $structured['title']);
        $this->assertSame('#b91c1c', $structured['color']);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_tags',
            'arguments' => [],
        ]);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertSame(['urgent'], array_column($items, 'title'));
    }

    public function testUpdateTaskSetsCustomFieldValue(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->makeProject($alice, [$alice], 'Fielded');
        $field = $this->makeCustomFieldDefinition($board, 'Notes', 'text');
        $task = $this->makeTaskInProject($alice, $board, 'With fields');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'update_task',
            'arguments' => [
                'taskId' => (string) $task->getId(),
                'customFieldValues' => [
                    ['definitionId' => (string) $field->getId(), 'value' => 'hello world'],
                ],
            ],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $values = $structured['customFieldValues'] ?? null;
        $this->assertIsArray($values);
        $this->assertCount(1, $values);
        $this->assertIsArray($values[0]);
        $this->assertSame('hello world', $values[0]['value']);
    }

    public function testAddAndListPageComment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->makeSpace($alice);
        $page = $this->makePage($alice, $space, 'Spec');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'add_page_comment',
            'arguments' => ['pageId' => (string) $page->getId(), 'body' => 'First note'],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('First note', $structured['body']);
        $this->assertSame('page', $structured['commentableType']);
        $this->assertSame((string) $page->getId(), $structured['pageId']);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_page_comments',
            'arguments' => ['pageId' => (string) $page->getId()],
        ]);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);
        $this->assertSame(['First note'], array_column($items, 'body'));
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

    public function testTokenAccessPolicyEnforcesReadOnly(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Read me');
        // tasks=view: reads allowed, writes denied.
        $plain = $this->mintToken($alice, 'Read-only', accessPolicy: [
            'categories' => ['tasks' => 'view'],
            'items' => [],
        ]);

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
        $this->assertStringContainsString('scope', $content[0]['text']);
    }

    public function testGetAnalyticsReturnsSeriesForASpaceAdmin(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->makeSpace($alice);
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_analytics',
            'arguments' => ['spaceId' => (string) $space->getId(), 'interval' => 'month'],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);

        $keys = $this->analyticsMetricKeys($body);
        $this->assertContains('invoiced', $keys);
        $this->assertContains('tracked_time', $keys);
    }

    /**
     * The gate that only exists on the MCP side: this one tool spans two
     * permission categories, so a token narrowed to invoices must not pull the
     * owner's time metrics through it.
     */
    public function testGetAnalyticsHonoursTheTokensOwnScopePerMetric(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->makeSpace($alice);
        $plain = $this->mintToken($alice, 'Invoices only', accessPolicy: [
            'categories' => ['invoices' => 'view'],
            'items' => [],
        ]);

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_analytics',
            'arguments' => ['spaceId' => (string) $space->getId()],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);

        $keys = $this->analyticsMetricKeys($body);
        $this->assertContains('invoiced', $keys, 'The token is scoped to invoices.');
        $this->assertNotContains(
            'tracked_time',
            $keys,
            'A token scoped to invoices must not widen into time metrics.',
        );
    }

    public function testGetAnalyticsRejectsForeignSpacesAndBadInput(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $theirs = $this->makeSpace($bob, 'Bob Space');
        $mine = $this->makeSpace($alice);
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_analytics',
            'arguments' => ['spaceId' => (string) $theirs->getId()],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_analytics',
            'arguments' => ['spaceId' => (string) $mine->getId(), 'interval' => 'fortnight'],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_analytics',
            'arguments' => ['spaceId' => (string) $mine->getId(), 'metrics' => ['profit']],
        ]);
        $this->assertTrue($body['result']['isError'] ?? null);
    }

    public function testListAutomationsFlattensARuleIntoWhenIfThen(): void
    {
        $alice = $this->createUser('alice@example.com');
        $board = $this->makeProject($alice, [], 'Automated');

        $rule = new \App\Entity\Automation();
        $rule->setBoard($board)
            ->setName('Tag on completion')
            ->setTriggerEvent(\App\Automation\AutomationEvents::TASK_COMPLETED)
            ->setGraph([
                'nodes' => [
                    ['id' => 't', 'kind' => 'trigger', 'type' => 'task.completed', 'config' => []],
                    ['id' => 'c', 'kind' => 'condition', 'type' => 'task.has_tag', 'config' => ['tag' => 'urgent']],
                    ['id' => 'a', 'kind' => 'action', 'type' => 'task.add_tag', 'config' => ['tag' => 'done']],
                ],
                'edges' => [['from' => 't', 'to' => 'c'], ['from' => 'c', 'to' => 'a']],
            ])
            ->setCreatedBy($alice);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $plain = $this->mintToken($alice, 'CLI');
        $client = static::createClient();

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_automations',
            'arguments' => ['boardId' => (string) $board->getId()],
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);

        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'];
        $this->assertIsArray($items);
        $this->assertCount(1, $items);
        $this->assertIsArray($items[0]);

        // Flattened rather than handing back raw nodes and edges — a model can
        // reason about a sentence, not an adjacency list.
        $summary = $items[0]['summary'];
        $this->assertIsString($summary);
        $this->assertStringContainsString('when task.completed', $summary);
        $this->assertStringContainsString('only if task.has_tag', $summary);
        $this->assertStringContainsString('then task.add_tag', $summary);

        // The runs tool reads the same rule cleanly with no history yet.
        $runs = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_automation_runs',
            'arguments' => ['automationId' => (string) $rule->getId()],
        ]);
        $this->assertFalse($runs['result']['isError'] ?? null);
        $runsStructured = $runs['result']['structuredContent'] ?? null;
        $this->assertIsArray($runsStructured);
        $this->assertSame([], $runsStructured['items']);
    }

    public function testAutomationToolsHideAnotherUsersBoard(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $board = $this->makeProject($bob, [], "Bob's board");

        $plain = $this->mintToken($alice, 'CLI');
        $client = static::createClient();

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_automations',
            'arguments' => ['boardId' => (string) $board->getId()],
        ]);

        // Existence-hiding, same as every other board-scoped tool.
        $this->assertTrue($body['result']['isError'] ?? null);
    }

    public function testEveryRegisteredToolHasAPolicyMapping(): void
    {
        // Guards against a new MCP tool silently defaulting to "allowed under
        // any policy" — every tool must be mapped in McpToolPolicy.
        $registry = static::getContainer()->get(\App\Mcp\McpToolRegistry::class);
        foreach ($registry->all() as $tool) {
            $name = $tool->getName();
            $this->assertNotNull(
                \App\Mcp\McpToolPolicy::mapping($name),
                sprintf('MCP tool "%s" has no mapping in McpToolPolicy.', $name),
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

    public function testLinkTasksCreatesASubtaskRelationship(): void
    {
        $alice = $this->createUser('alice@example.com');
        $parent = $this->makeTask($alice, 'Parent');
        $child = $this->makeTask($alice, 'Child');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'link_tasks',
            'arguments' => [
                'sourceTaskId' => (string) $parent->getId(),
                'targetTaskId' => (string) $child->getId(),
                'type' => 'parent',
            ],
        ]);

        $this->assertFalse($body['result']['isError'] ?? null);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('parent', $structured['type']);
        $this->assertSame('parent of', $structured['label']);

        // And it's visible from the parent's viewpoint.
        $list = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_task_relationships',
            'arguments' => ['taskId' => (string) $parent->getId()],
        ]);
        $items = $this->relationshipItems($list);
        $this->assertCount(1, $items);
        $first = $items[0];
        $this->assertIsArray($first);
        $this->assertSame('parent of', $first['label']);
    }

    public function testGetTaskCarriesTheSubtaskRollup(): void
    {
        $alice = $this->createUser('alice@example.com');
        $parent = $this->makeTask($alice, 'Parent');
        $child = $this->makeTask($alice, 'Child');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'link_tasks',
            'arguments' => [
                'sourceTaskId' => (string) $parent->getId(),
                'targetTaskId' => (string) $child->getId(),
                'type' => 'parent',
            ],
        ]);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_task',
            'arguments' => ['taskId' => (string) $parent->getId()],
        ]);
        $structured = $body['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame(['total' => 1, 'completed' => 0], $structured['subtasks']);

        // A childless task still reports the zeroed shape rather than omitting
        // the key, so the model needn't special-case it.
        $solo = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'get_task',
            'arguments' => ['taskId' => (string) $child->getId()],
        ]);
        $soloStructured = $solo['result']['structuredContent'] ?? null;
        $this->assertIsArray($soloStructured);
        $this->assertSame(['total' => 0, 'completed' => 0], $soloStructured['subtasks']);
    }

    public function testLinkTasksRejectsSelfLink(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->makeTask($alice, 'Solo');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'link_tasks',
            'arguments' => [
                'sourceTaskId' => (string) $task->getId(),
                'targetTaskId' => (string) $task->getId(),
                'type' => 'related',
            ],
        ]);

        // ValidTaskRelationship's self-link rule surfaces as a tool error, not
        // a protocol error, so the model can recover.
        $this->assertTrue($body['result']['isError'] ?? null);
    }

    public function testLinkTasksHidesTasksTheCallerCannotEdit(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $mine = $this->makeTask($alice, 'Mine');
        $bobsTask = $this->makeTask($bob, 'Private');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'link_tasks',
            'arguments' => [
                'sourceTaskId' => (string) $mine->getId(),
                'targetTaskId' => (string) $bobsTask->getId(),
                'type' => 'related',
            ],
        ]);

        // 404-shaped: Alice learns nothing about Bob's task.
        $this->assertTrue($body['result']['isError'] ?? null);
    }

    public function testUnlinkTasksRemovesTheRelationship(): void
    {
        $alice = $this->createUser('alice@example.com');
        $a = $this->makeTask($alice, 'A');
        $b = $this->makeTask($alice, 'B');
        $plain = $this->mintToken($alice, 'CLI');

        $client = static::createClient();
        $args = [
            'sourceTaskId' => (string) $a->getId(),
            'targetTaskId' => (string) $b->getId(),
            'type' => 'required',
        ];
        $this->callMcp($client, $plain, 'tools/call', ['name' => 'link_tasks', 'arguments' => $args]);

        $body = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'unlink_tasks',
            'arguments' => $args,
        ]);
        $this->assertFalse($body['result']['isError'] ?? null);

        $list = $this->callMcp($client, $plain, 'tools/call', [
            'name' => 'list_task_relationships',
            'arguments' => ['taskId' => (string) $a->getId()],
        ]);
        $this->assertCount(0, $this->relationshipItems($list));
    }

    /**
     * Narrow the `items` array out of a list_task_relationships tool response.
     *
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    private function relationshipItems(array $body): array
    {
        $result = $body['result'] ?? null;
        $this->assertIsArray($result);
        $structured = $result['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $items = $structured['items'] ?? null;
        $this->assertIsArray($items);

        return $items;
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

    private function makePage(User $author, Space $space, string $title): Page
    {
        $page = new Page();
        $page->setCreatedBy($author);
        $page->setSpace($space);
        $page->setTitle($title);
        $this->entityManager->persist($page);
        $this->entityManager->flush();
        return $page;
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

    /**
     * A shared space with `$admin` as its admin. `createUser()` doesn't
     * provision the signup-time personal space, so page tools
     * that need a real space get one here.
     */
    private function makeSpace(User $admin, string $name = 'Team Space'): Space
    {
        $space = new Space();
        $space->setName($name);
        $space->setCreatedBy($admin);
        $space->addUserMembership(
            (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN),
        );
        $this->entityManager->persist($space);
        $this->entityManager->flush();
        return $space;
    }

    private function makeCustomFieldDefinition(Board $board, string $name, string $type): CustomFieldDefinition
    {
        $field = new CustomFieldDefinition();
        $field->setBoard($board);
        $field->setName($name);
        $field->setType($type);
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    /**
     * The metric keys in a `get_analytics` tool result. Tool payloads come back
     * as JSON inside the text content block, so it has to be decoded first.
     *
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function analyticsMetricKeys(array $body): array
    {
        $result = $body['result'] ?? null;
        $this->assertIsArray($result);
        $content = $result['content'] ?? null;
        $this->assertIsArray($content);
        $this->assertIsArray($content[0]);
        $this->assertIsString($content[0]['text']);

        $payload = json_decode($content[0]['text'], true);
        $this->assertIsArray($payload);
        $metrics = $payload['metrics'] ?? null;
        $this->assertIsArray($metrics);

        $keys = [];
        foreach ($metrics as $metric) {
            $this->assertIsArray($metric);
            $this->assertIsString($metric['key']);
            $keys[] = $metric['key'];
        }

        return $keys;
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
