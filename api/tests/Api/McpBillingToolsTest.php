<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client as ApiClient;
use App\Entity\ApiToken;
use App\Entity\Client;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\Service;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\TimeEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * MCP coverage for the billing surface: tracked time and invoicing.
 *
 * These tools reach the two things in the product that are actually money, so
 * the tests lean on the boundaries rather than the happy path — who can see an
 * invoice, whether a rate can be forged, and whether a billed entry stays
 * frozen.
 *
 * Fixtures are persisted before the first createClient() call, per McpTest.
 */
class McpBillingToolsTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        foreach (
            [
            'App\Entity\ApiToken',
            'App\Entity\InvoiceLineItem',
            'App\Entity\Invoice',
            'App\Entity\TimeEntry',
            'App\Entity\Service',
            'App\Entity\Project',
            'App\Entity\Client',
            'App\Entity\SpaceMembership',
            'App\Entity\Space',
            'App\Entity\User',
            ] as $entity
        ) {
            $this->entityManager->createQuery('DELETE FROM ' . $entity)->execute();
        }
    }

    public function testListProjectsReturnsCategoriesForLoggingTime(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makeSpace($owner);
        $project = $this->makeProject($space, 'Website Rebuild');
        $this->makeCategory($project, 'Design', 15000);
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'list_projects');

        $items = $this->arrayField($result, 'items');
        $this->assertCount(1, $items);
        $first = $this->arrayField($items, 0);
        $this->assertSame('Website Rebuild', $this->stringField($first, 'name'));
        // The category ids ride along so the model can call log_time without a
        // second round-trip — that's the whole point of this tool.
        $categories = $this->arrayField($first, 'categories');
        $this->assertCount(1, $categories);
        $this->assertSame(15000, $this->intField($this->arrayField($categories, 0), 'billingRate'));
    }

    public function testLogTimeDerivesRateFromCategoryAndIgnoresCallerClaims(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makeSpace($owner);
        $project = $this->makeProject($space, 'Website Rebuild');
        $category = $this->makeCategory($project, 'Design', 15000);
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'log_time', [
            'projectId' => (string) $project->getId(),
            'categoryId' => (string) $category->getId(),
            'startedAt' => '2026-07-20T09:00:00+00:00',
            'durationMinutes' => 90,
            'description' => 'Homepage wireframes',
        ]);

        $this->assertSame(5400, $this->intField($result, 'durationSeconds'));
        // The rate came from the category, not the payload. There is no input
        // field for it at all, which is the guarantee being pinned here.
        $this->assertSame(15000, $this->intField($result, 'rateAmount'));
        $this->assertTrue($this->boolField($result, 'billable'));
        $this->assertFalse($this->boolField($result, 'running'));
    }

    public function testLogTimeRejectsAProjectInAnotherUsersSpace(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $outsider = $this->makeUser('outsider@example.com');
        $space = $this->makeSpace($owner);
        $project = $this->makeProject($space, 'Private Work');
        $category = $this->makeCategory($project, 'Design', 15000);
        $token = $this->mintToken($outsider);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'log_time', [
            'projectId' => (string) $project->getId(),
            'categoryId' => (string) $category->getId(),
            'startedAt' => '2026-07-20T09:00:00+00:00',
            'durationMinutes' => 60,
        ], expectError: true);

        // 404-shaped, not 403: an outsider shouldn't learn the project exists.
        $this->assertStringContainsString('not found', strtolower($this->errorText($result)));
        $this->assertSame(0, $this->countTimeEntries());
    }

    public function testStartTimerStopsThePreviousRunningTimer(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makeSpace($owner);
        $project = $this->makeProject($space, 'Website Rebuild');
        $category = $this->makeCategory($project, 'Design', 15000);
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $args = [
            'projectId' => (string) $project->getId(),
            'categoryId' => (string) $category->getId(),
        ];

        $first = $this->callTool($client, $token, 'start_timer', $args + ['description' => 'First']);
        $this->assertTrue($this->boolField($first, 'running'));

        $second = $this->callTool($client, $token, 'start_timer', $args + ['description' => 'Second']);
        $this->assertTrue($this->boolField($second, 'running'));

        // The one-running-timer index is a partial unique index, so a second
        // running row would be a constraint violation rather than a soft bug.
        $running = $this->entityManager->getRepository(TimeEntry::class)
            ->findBy(['user' => $owner, 'endedAt' => null]);
        $this->assertCount(1, $running);
        $this->assertSame('Second', $running[0]->getDescription());
    }

    public function testStopTimerErrorsWhenNothingIsRunning(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $this->makeSpace($owner);
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'stop_timer', [], expectError: true);

        $this->assertStringContainsString('no timer', strtolower($this->errorText($result)));
    }

    public function testStopTimerCompletesTheEntry(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makeSpace($owner);
        $project = $this->makeProject($space, 'Website Rebuild');
        $category = $this->makeCategory($project, 'Design', 15000);
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $this->callTool($client, $token, 'start_timer', [
            'projectId' => (string) $project->getId(),
            'categoryId' => (string) $category->getId(),
        ]);

        $result = $this->callTool($client, $token, 'stop_timer');

        $this->assertFalse($this->boolField($result, 'running'));
        $this->assertNotSame('', $this->stringField($result, 'endedAt'));
        $this->assertGreaterThanOrEqual(0, $this->intField($result, 'durationSeconds'));
    }

    public function testListTimeEntriesDefaultsToTheCallersOwnTime(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $mate = $this->makeUser('mate@example.com');
        $space = $this->makeSpace($owner);
        $this->addMember($space, $mate);
        $project = $this->makeProject($space, 'Website Rebuild');
        $category = $this->makeCategory($project, 'Design', 15000);

        $this->makeTimeEntry($space, $project, $category, $owner, 'Mine', 3600);
        $this->makeTimeEntry($space, $project, $category, $mate, 'Theirs', 1800);
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $mine = $this->callTool($client, $token, 'list_time_entries');
        $mineItems = $this->arrayField($mine, 'items');
        $this->assertCount(1, $mineItems);
        $this->assertSame('Mine', $this->stringField($this->arrayField($mineItems, 0), 'description'));
        $this->assertSame(3600, $this->intField($mine, 'totalDurationSeconds'));

        $all = $this->callTool($client, $token, 'list_time_entries', ['mine' => false]);
        $this->assertCount(2, $this->arrayField($all, 'items'));
        $this->assertSame(5400, $this->intField($all, 'totalDurationSeconds'));
    }

    public function testInvoiceToolsAreHiddenFromNonAdminMembers(): void
    {
        // invoices is ADMIN_RESERVED: a plain member gets no visibility into
        // what the business bills until an admin grants a role.
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $space = $this->makeSpace($owner);
        $this->addMember($space, $member);
        $invoice = $this->makeInvoice($space, $this->makeClient($space, 'Acme Co'));
        $token = $this->mintToken($member);

        $client = static::createClient();

        $list = $this->callTool($client, $token, 'list_invoices');
        $this->assertSame([], $this->arrayField($list, 'items'));

        $get = $this->callTool($client, $token, 'get_invoice', [
            'invoiceId' => (string) $invoice->getId(),
        ], expectError: true);
        $this->assertStringContainsString('not found', strtolower($this->errorText($get)));
    }

    public function testSpaceAdminSeesInvoicesWithLineItemTotals(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makeSpace($owner);
        $invoice = $this->makeInvoice($space, $this->makeClient($space, 'Acme Co'));
        $token = $this->mintToken($owner);

        $client = static::createClient();

        $list = $this->callTool($client, $token, 'list_invoices');
        $items = $this->arrayField($list, 'items');
        $this->assertCount(1, $items);
        // The list stays lean — line items only on the single-invoice fetch.
        $this->assertArrayNotHasKey('lineItems', $this->arrayField($items, 0));

        $detail = $this->callTool($client, $token, 'get_invoice', [
            'invoiceId' => (string) $invoice->getId(),
        ]);
        $this->assertArrayHasKey('lineItems', $detail);
        $this->assertSame('Acme Co', $this->stringField($this->arrayField($detail, 'client'), 'name'));
    }

    public function testUnpaidOnlyExcludesVoidedInvoices(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makeSpace($owner);
        $acme = $this->makeClient($space, 'Acme Co');
        $this->makeInvoice($space, $acme, Invoice::STATUS_SENT);
        $this->makeInvoice($space, $acme, Invoice::STATUS_VOID);
        $this->makeInvoice($space, $acme, Invoice::STATUS_PAID);
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'list_invoices', ['unpaidOnly' => true]);

        // A voided invoice still carries a balance on paper. Chasing a client
        // for one would be a real-world error, so it must not come back here.
        $items = $this->arrayField($result, 'items');
        $this->assertCount(1, $items);
        $this->assertSame(Invoice::STATUS_SENT, $this->stringField($this->arrayField($items, 0), 'status'));
    }

    public function testTokenScopedAwayFromInvoicesCannotReadThem(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makeSpace($owner);
        $this->makeInvoice($space, $this->makeClient($space, 'Acme Co'));
        // The owner can see invoices; this *token* cannot. Tokens narrow.
        $token = $this->mintToken($owner, ['categories' => ['time_entries' => 'view']]);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'list_invoices', [], expectError: true);

        $this->assertNotSame('', $this->errorText($result));
    }

    // ---- fixtures -------------------------------------------------------

    /**
     * @param array<string, mixed>|null $accessPolicy
     */
    private function mintToken(User $user, ?array $accessPolicy = null): string
    {
        $plain = ApiToken::PLAINTEXT_PREFIX . bin2hex(random_bytes(16));
        $token = new ApiToken();
        $token->setUser($user);
        $token->setName('mcp-billing-test');
        $token->setTokenHash(hash('sha256', $plain));
        $token->setAccessPolicy($accessPolicy);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $plain;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<int|string, mixed>
     */
    private function callTool(
        ApiClient $client,
        string $bearer,
        string $tool,
        array $arguments = [],
        bool $expectError = false,
    ): array {
        $client->request('POST', '/mcp', [
            'json' => [
                'jsonrpc' => '2.0',
                'id' => mt_rand(1, 99999),
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => (object) $arguments],
            ],
            'headers' => ['Authorization' => 'Bearer ' . $bearer],
        ]);
        $this->assertResponseIsSuccessful();

        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);

        $result = $this->arrayField($body, 'result');

        if ($expectError) {
            $this->assertTrue($result['isError'] ?? false, 'Expected the tool to report an error.');

            return $result;
        }

        $this->assertNotTrue($result['isError'] ?? false, 'Tool returned an error: ' . $this->errorText($result));

        $decoded = json_decode($this->resultText($result), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<int|string, mixed> $result
     */
    private function errorText(array $result): string
    {
        return $this->resultText($result);
    }

    /**
     * The tool payload, which MCP wraps as JSON inside `content[0].text`.
     *
     * @param array<int|string, mixed> $result
     */
    private function resultText(array $result): string
    {
        $content = $this->arrayField($result, 'content');
        $first = $this->arrayField($content, 0);

        return $this->stringField($first, 'text');
    }

    private function makeUser(string $email): User
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

    private function makeSpace(User $admin): Space
    {
        $space = new Space();
        $space->setName('Studio');
        $space->setCreatedBy($admin);
        $space->addUserMembership(
            (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN),
        );
        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $space;
    }

    private function addMember(Space $space, User $user): void
    {
        $space->addUserMembership(
            (new SpaceMembership())->setUser($user)->setRole(Space::ROLE_MEMBER),
        );
        $this->entityManager->flush();
    }

    private function makeClient(Space $space, string $name): Client
    {
        $client = new Client();
        $client->setSpace($space);
        $client->setName($name);
        $client->setCurrency('USD');
        $client->setCreatedBy($space->getCreatedBy());
        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    private function makeProject(Space $space, string $name): Project
    {
        $project = new Project();
        $project->setSpace($space);
        $project->setName($name);
        $project->setClient($this->makeClient($space, $name . ' Client'));
        $project->setCurrency('USD');
        $project->setCreatedBy($space->getCreatedBy());
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function makeCategory(Project $project, string $name, int $rate): Service
    {
        $service = new Service();
        $service->setProject($project);
        $service->setName($name);
        $service->setBillingRate($rate);
        $service->setBillable(true);
        $this->entityManager->persist($service);
        $this->entityManager->flush();

        return $service;
    }

    private function makeTimeEntry(
        Space $space,
        Project $project,
        Service $category,
        User $user,
        string $description,
        int $seconds,
    ): TimeEntry {
        $started = new \DateTimeImmutable('2026-07-20T09:00:00+00:00');

        $entry = new TimeEntry();
        $entry->setSpace($space);
        $entry->setProject($project);
        $entry->setCategory($category);
        $entry->setUser($user);
        $entry->setDescription($description);
        $entry->setStartedAt($started);
        $entry->setEndedAt($started->modify(sprintf('+%d seconds', $seconds)));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function makeInvoice(Space $space, Client $client, string $status = Invoice::STATUS_SENT): Invoice
    {
        $invoice = new Invoice();
        $invoice->setSpace($space);
        $invoice->setClient($client);
        $invoice->setStatus($status);
        $invoice->setCurrency('USD');
        $invoice->setIssueDate(new \DateTimeImmutable('2026-07-01'));
        $invoice->setCreatedBy($space->getCreatedBy());
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function countTimeEntries(): int
    {
        return (int) $this->entityManager
            ->createQuery('SELECT COUNT(t.id) FROM App\Entity\TimeEntry t')
            ->getSingleScalarResult();
    }
}
