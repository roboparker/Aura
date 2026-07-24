<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client as ApiClient;
use App\Entity\ApiToken;
use App\Entity\Client;
use App\Entity\Estimate;
use App\Entity\Expense;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * MCP coverage for the remaining workspace surfaces: the calendar projection,
 * the notification inbox, expenses, and estimates.
 *
 * The interesting cases are the ones where the tool's shape differs from the
 * underlying entity — a recurring task fanning out into occurrences, and an
 * inbox that must never leak another user's rows.
 */
class McpWorkspaceToolsTest extends ApiTestCase
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
            'App\Entity\Notification',
            'App\Entity\Estimate',
            'App\Entity\Expense',
            'App\Entity\Task',
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

    public function testCalendarExpandsRecurringTasksIntoOccurrences(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makePersonalSpace($owner);

        // Weekly from Mon 2026-07-06 — four Mondays fall inside July 6–31.
        $recurring = $this->makeTask($owner, 'Weekly standup', '2026-07-06T09:00:00+00:00');
        $recurring->setRecurrenceRule(['frequency' => 'weekly', 'interval' => 1]);
        $this->makeTask($owner, 'One-off review', '2026-07-15T09:00:00+00:00');
        $this->entityManager->flush();

        $token = $this->mintToken($owner);
        $client = static::createClient();

        $result = $this->callTool($client, $token, 'list_calendar_events', [
            'spaceId' => (string) $space->getId(),
            'start' => '2026-07-06',
            'end' => '2026-07-31',
        ]);

        $items = $this->arrayField($result, 'items');
        $dates = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            $dates[] = $this->stringField($item, 'occurrenceDate');
        }
        sort($dates);

        // The recurring anchor contributes one entry per Monday, not one entry
        // total — that expansion is the whole reason this tool exists.
        $this->assertSame(
            ['2026-07-06', '2026-07-13', '2026-07-15', '2026-07-20', '2026-07-27'],
            $dates,
        );

        $recurringFlags = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            $recurringFlags[$this->stringField($item, 'occurrenceDate')] = $this->boolField($item, 'recurring');
        }
        $this->assertFalse($recurringFlags['2026-07-15']);
        $this->assertTrue($recurringFlags['2026-07-13']);
    }

    public function testCalendarRejectsAnOversizedRange(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makePersonalSpace($owner);
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'list_calendar_events', [
            'spaceId' => (string) $space->getId(),
            'start' => '2026-01-01',
            'end' => '2026-12-31',
        ], expectError: true);

        $this->assertStringContainsString('too large', strtolower($this->errorText($result)));
    }

    public function testCalendarHidesSpacesTheCallerIsNotIn(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $outsider = $this->makeUser('outsider@example.com');
        $space = $this->makePersonalSpace($owner);
        $token = $this->mintToken($outsider);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'list_calendar_events', [
            'spaceId' => (string) $space->getId(),
            'start' => '2026-07-01',
            'end' => '2026-07-31',
        ], expectError: true);

        $this->assertStringContainsString('not found', strtolower($this->errorText($result)));
    }

    public function testNotificationsAreScopedToTheRecipient(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $other = $this->makeUser('other@example.com');
        $this->makeNotification($owner, 'Yours');
        $this->makeNotification($other, 'Theirs');
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'list_notifications');

        $items = $this->arrayField($result, 'items');
        $this->assertCount(1, $items);
        $this->assertSame('Yours', $this->stringField($this->arrayField($items, 0), 'title'));
    }

    public function testMarkNotificationsReadRequiresIdsOrAll(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $this->makeNotification($owner, 'Unread');
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'mark_notifications_read', [], expectError: true);

        // No implicit "clear everything" — an inbox the user hasn't read is
        // exactly the thing an agent shouldn't wipe by omission.
        $this->assertStringContainsString('all=true', $this->errorText($result));
    }

    public function testMarkNotificationsReadCannotTouchAnotherInbox(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $other = $this->makeUser('other@example.com');
        $theirs = $this->makeNotification($other, 'Theirs');
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'mark_notifications_read', [
            'notificationIds' => [(string) $theirs->getId()],
        ]);

        $this->assertSame(0, $this->intField($result, 'markedRead'));
        $this->assertFalse($this->reloadNotification($theirs)->isRead());
    }

    public function testMarkAllClearsOnlyTheCallersUnread(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $other = $this->makeUser('other@example.com');
        $this->makeNotification($owner, 'Mine A');
        $this->makeNotification($owner, 'Mine B');
        $theirs = $this->makeNotification($other, 'Theirs');
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'mark_notifications_read', ['all' => true]);

        $this->assertSame(2, $this->intField($result, 'markedRead'));
        $this->assertFalse($this->reloadNotification($theirs)->isRead());
    }

    public function testLogExpenseRecordsAgainstAProject(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makeSpace($owner);
        $project = $this->makeProject($space, 'Website Rebuild');
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'log_expense', [
            'projectId' => (string) $project->getId(),
            'amount' => 4250,
            'spentOn' => '2026-07-20',
            'description' => 'Stock photography',
        ]);

        $this->assertSame(4250, $this->intField($result, 'amount'));
        $this->assertSame('USD', $this->stringField($result, 'currency'));
        $this->assertSame('2026-07-20', $this->stringField($result, 'spentOn'));
        $this->assertTrue($this->boolField($result, 'billable'));
    }

    public function testLogExpenseRejectsANonPositiveAmount(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makeSpace($owner);
        $project = $this->makeProject($space, 'Website Rebuild');
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'log_expense', [
            'projectId' => (string) $project->getId(),
            'amount' => 0,
        ], expectError: true);

        $this->assertStringContainsString('positive', strtolower($this->errorText($result)));
    }

    public function testExpensesUseTheTimeEntriesPermissionNotInvoices(): void
    {
        // A plain member can't see invoices (admin-reserved) but *can* see
        // expenses — they ride time_entries, mirroring the REST rules.
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $space = $this->makeSpace($owner);
        $this->addMember($space, $member);
        $project = $this->makeProject($space, 'Website Rebuild');
        $this->makeExpense($space, $project, $member, 1000);
        $token = $this->mintToken($member);

        $client = static::createClient();

        $expenses = $this->callTool($client, $token, 'list_expenses');
        $this->assertCount(1, $this->arrayField($expenses, 'items'));

        $estimates = $this->callTool($client, $token, 'list_estimates');
        $this->assertSame([], $this->arrayField($estimates, 'items'));
    }

    public function testSpaceAdminSeesEstimates(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $space = $this->makeSpace($owner);
        $this->makeEstimate($space, $this->makeClient($space, 'Acme Co'), Estimate::STATUS_SENT);
        $token = $this->mintToken($owner);

        $client = static::createClient();
        $result = $this->callTool($client, $token, 'list_estimates', ['status' => Estimate::STATUS_SENT]);

        $items = $this->arrayField($result, 'items');
        $this->assertCount(1, $items);
        $this->assertSame('Acme Co', $this->stringField(
            $this->arrayField($this->arrayField($items, 0), 'client'),
            'name',
        ));
    }

    // ---- fixtures -------------------------------------------------------

    private function mintToken(User $user): string
    {
        $plain = ApiToken::PLAINTEXT_PREFIX . bin2hex(random_bytes(16));
        $token = new ApiToken();
        $token->setUser($user);
        $token->setName('mcp-workspace-test');
        $token->setTokenHash(hash('sha256', $plain));
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
     * @param array<int|string, mixed> $result
     */
    private function resultText(array $result): string
    {
        $content = $this->arrayField($result, 'content');

        return $this->stringField($this->arrayField($content, 0), 'text');
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

    private function makeSpace(User $admin, bool $personal = false): Space
    {
        $space = new Space();
        $space->setName($personal ? 'Private' : 'Studio');
        $space->setCreatedBy($admin);
        $space->setIsPersonal($personal);
        $space->addUserMembership(
            (new SpaceMembership())->setUser($admin)->setRole(Space::ROLE_ADMIN),
        );
        $this->entityManager->persist($space);
        $this->entityManager->flush();

        return $space;
    }

    private function makePersonalSpace(User $admin): Space
    {
        return $this->makeSpace($admin, personal: true);
    }

    private function addMember(Space $space, User $user): void
    {
        $space->addUserMembership(
            (new SpaceMembership())->setUser($user)->setRole(Space::ROLE_MEMBER),
        );
        $this->entityManager->flush();
    }

    private function makeTask(User $owner, string $title, string $dueDate): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        $task->setDueDate(new \DateTimeImmutable($dueDate));
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    /**
     * The MCP call reboots the kernel between requests, detaching entities
     * created in the test. Re-fetch by id to read post-call DB state.
     */
    private function reloadNotification(Notification $notification): Notification
    {
        $fresh = $this->entityManager->getRepository(Notification::class)->find($notification->getId());
        self::assertInstanceOf(Notification::class, $fresh);

        return $fresh;
    }

    private function makeNotification(User $recipient, string $title): Notification
    {
        $notification = new Notification();
        $notification->setRecipient($recipient);
        $notification->setType(Notification::TYPE_MENTION);
        $notification->setTitle($title);
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
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

    private function makeExpense(Space $space, Project $project, User $user, int $amount): Expense
    {
        $expense = new Expense();
        $expense->setSpace($space);
        $expense->setProject($project);
        $expense->setUser($user);
        $expense->setAmount($amount);
        $expense->setCurrency('USD');
        $expense->setSpentOn(new \DateTimeImmutable('2026-07-20'));
        $expense->setBillable(true);
        $this->entityManager->persist($expense);
        $this->entityManager->flush();

        return $expense;
    }

    private function makeEstimate(Space $space, Client $client, string $status): Estimate
    {
        $estimate = new Estimate();
        $estimate->setSpace($space);
        $estimate->setClient($client);
        $estimate->setStatus($status);
        $estimate->setCurrency('USD');
        $estimate->setCreatedBy($space->getCreatedBy());
        $this->entityManager->persist($estimate);
        $this->entityManager->flush();

        return $estimate;
    }
}
