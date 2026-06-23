<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TaskTest extends ApiTestCase
{
    use SpaceMembershipFixture;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // Notification + Comment hold FKs to Task; clear them first so
        // the bulk Task delete below doesn't fail when search-fixture
        // comments are still around from a previous test class.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Comment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Project')->execute();
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
        $this->assertTrue($user->getId()?->equals($task->getOwner()?->getId()));
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
        $this->assertTrue($alice->getId()?->equals($task->getOwner()?->getId()), 'Owner must be overwritten by the processor.');
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

    public function testAdminIsScopedLikeNormalUser(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);

        $this->createTask($alice, 'A1');
        $this->createTask($bob, 'B1');
        $this->createTask($bob, 'B2');
        $this->createTask($admin, 'Admin task');

        $client = static::createClient();
        $client->loginUser($admin);
        $client->request('GET', '/tasks');

        $this->assertResponseIsSuccessful();
        // No admin bypass: the admin sees only their own task, not other
        // users'. Cross-user reach is via impersonation (switch_user) only.
        $this->assertJsonContains(['totalItems' => 1]);
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

    public function testCreateTaskWithDueDate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $due = (new \DateTimeImmutable('2026-06-01T09:00:00+00:00'));

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Submit report',
                'dueDate' => $due->format(\DateTimeInterface::ATOM),
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'Task',
            'title' => 'Submit report',
        ]);
        $task = $this->reloadTaskByTitle('Submit report');
        $this->assertNotNull($task->getDueDate());
        $this->assertSame($due->getTimestamp(), $task->getDueDate()->getTimestamp());
    }

    public function testPatchDueDate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Plan trip');
        $this->assertNull($task->getDueDate());

        $client = static::createClient();
        $client->loginUser($alice);
        $due = new \DateTimeImmutable('2026-07-04T12:00:00+00:00');
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['dueDate' => $due->format(\DateTimeInterface::ATOM)],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $reloaded = $this->reloadTaskByTitle('Plan trip');
        $this->assertNotNull($reloaded->getDueDate());
        $this->assertSame($due->getTimestamp(), $reloaded->getDueDate()->getTimestamp());
    }

    public function testClearDueDate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Has due date');
        $task->setDueDate(new \DateTimeImmutable('2026-07-04T12:00:00+00:00'));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['dueDate' => null],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $reloaded = $this->reloadTaskByTitle('Has due date');
        $this->assertNull($reloaded->getDueDate());
    }

    public function testRejectsInvalidDueDateFormat(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Bad date');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['dueDate' => 'not-a-real-date'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateTaskWithRecurrenceRule(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Water the plants',
                'dueDate' => '2026-05-10T00:00:00+00:00',
                'recurrenceRule' => ['frequency' => 'weekly', 'interval' => 1],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $task = $this->reloadTaskByTitle('Water the plants');
        $this->assertSame(
            ['frequency' => 'weekly', 'interval' => 1],
            $task->getRecurrenceRule(),
        );
    }

    public function testRecurrenceWithoutDueDateIsRejected(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Orphaned recurrence',
                'recurrenceRule' => ['frequency' => 'daily', 'interval' => 1],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testInvalidRecurrenceFrequencyIsRejected(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Bad freq',
                'dueDate' => '2026-05-10T00:00:00+00:00',
                'recurrenceRule' => ['frequency' => 'fortnightly', 'interval' => 1],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testInvalidRecurrenceIntervalIsRejected(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Bad interval',
                'dueDate' => '2026-05-10T00:00:00+00:00',
                'recurrenceRule' => ['frequency' => 'daily', 'interval' => 0],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * Completing a recurring task creates a fresh, incomplete task whose due
     * date is advanced per the rule. The original stays completed; tags,
     * description, project, and assignees carry over.
     *
     * @dataProvider provideRecurrenceAdvanceCases
     */
    public function testCompletingRecurringTaskCreatesNextOccurrence(
        string $frequency,
        int $interval,
        string $start,
        string $expectedNext,
    ): void {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Recurring');
        $task->setDueDate(new \DateTimeImmutable($start));
        $task->setDescription('Notes');
        $task->setRecurrenceRule(['frequency' => $frequency, 'interval' => $interval]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => '2026-06-01T12:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(Task::class);
        /** @var Task[] $all */
        $all = $repo->findBy(['title' => 'Recurring']);
        $this->assertCount(2, $all, 'Expected the original + a freshly cloned next occurrence.');

        $completedRows = array_values(array_filter($all, fn (Task $t) => null !== $t->getCompletedOn()));
        $pendingRows = array_values(array_filter($all, fn (Task $t) => null === $t->getCompletedOn()));
        $this->assertCount(1, $completedRows);
        $this->assertCount(1, $pendingRows);

        $next = $pendingRows[0];
        $this->assertNotNull($next->getDueDate());
        $this->assertSame(
            (new \DateTimeImmutable($expectedNext))->format('Y-m-d'),
            $next->getDueDate()->format('Y-m-d'),
        );
        $this->assertSame('Notes', $next->getDescription());
        $this->assertSame(
            ['frequency' => $frequency, 'interval' => $interval],
            $next->getRecurrenceRule(),
        );
    }

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function provideRecurrenceAdvanceCases(): array
    {
        return [
            'daily +1' => ['daily', 1, '2026-05-10T00:00:00+00:00', '2026-05-11'],
            'daily +3' => ['daily', 3, '2026-05-10T00:00:00+00:00', '2026-05-13'],
            'weekly +2' => ['weekly', 2, '2026-05-10T00:00:00+00:00', '2026-05-24'],
            'monthly +1' => ['monthly', 1, '2026-05-10T00:00:00+00:00', '2026-06-10'],
            'yearly +1' => ['yearly', 1, '2026-05-10T00:00:00+00:00', '2027-05-10'],
        ];
    }

    public function testCompletingNonRecurringTaskDoesNotClone(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'One-shot');
        $task->setDueDate(new \DateTimeImmutable('2026-05-10T00:00:00+00:00'));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => '2026-06-01T12:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $count = (int) $this->entityManager->createQuery(
            'SELECT COUNT(t) FROM App\Entity\Task t WHERE t.title = :title',
        )->setParameter('title', 'One-shot')->getSingleScalarResult();
        $this->assertSame(1, $count, 'Non-recurring task must not spawn an extra row.');
    }

    public function testReCompletingRecurringTaskDoesNotCreateAnotherOccurrence(): void
    {
        // Once a task is already completed, PATCHing completedOn again (e.g.
        // touching another field on the completed row) must not produce a
        // second clone — the trigger is the null → set transition only.
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Recurring twice');
        $task->setDueDate(new \DateTimeImmutable('2026-05-10T00:00:00+00:00'));
        $task->setRecurrenceRule(['frequency' => 'weekly', 'interval' => 1]);
        $task->setCompletedOn(new \DateTimeImmutable('2026-05-11T00:00:00+00:00'));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => '2026-05-12T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $count = (int) $this->entityManager->createQuery(
            'SELECT COUNT(t) FROM App\Entity\Task t WHERE t.title = :title',
        )->setParameter('title', 'Recurring twice')->getSingleScalarResult();
        $this->assertSame(1, $count);
    }

    public function testCompletingWeeklyByDayRecurrenceAdvancesToNextSelectedWeekday(): void
    {
        // 2026-06-01 is a Monday; with byDay = Mon/Wed/Fri the next
        // occurrence after a Monday is the Wednesday of the same week.
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'ByDay recurring');
        $task->setDueDate(new \DateTimeImmutable('2026-06-01T08:00:00+00:00'));
        $task->setRecurrenceRule([
            'frequency' => 'weekly',
            'interval' => 1,
            'byDay' => ['MO', 'WE', 'FR'],
        ]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => '2026-06-01T12:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(Task::class);
        /** @var Task[] $pending */
        $pending = array_values(array_filter(
            $repo->findBy(['title' => 'ByDay recurring']),
            fn (Task $t) => null === $t->getCompletedOn(),
        ));
        $this->assertCount(1, $pending);
        $this->assertNotNull($pending[0]->getDueDate());
        $this->assertSame('2026-06-03', $pending[0]->getDueDate()->format('Y-m-d'));
    }

    public function testCountOneRecurrenceDoesNotSpawn(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Last occurrence');
        $task->setDueDate(new \DateTimeImmutable('2026-06-01T08:00:00+00:00'));
        $task->setRecurrenceRule([
            'frequency' => 'daily',
            'interval' => 1,
            'ends' => ['type' => 'count', 'count' => 1],
        ]);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => '2026-06-01T12:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $count = (int) $this->entityManager->createQuery(
            'SELECT COUNT(t) FROM App\Entity\Task t WHERE t.title = :title',
        )->setParameter('title', 'Last occurrence')->getSingleScalarResult();
        $this->assertSame(1, $count, 'A count=1 series must not spawn a successor.');
    }

    public function testCountBasedRecurrenceSpawnsWithDecrementedCount(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Counted');
        $task->setDueDate(new \DateTimeImmutable('2026-06-01T08:00:00+00:00'));
        $task->setRecurrenceRule([
            'frequency' => 'daily',
            'interval' => 1,
            'ends' => ['type' => 'count', 'count' => 2],
        ]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['completedOn' => '2026-06-01T12:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(Task::class);
        /** @var Task[] $pending */
        $pending = array_values(array_filter(
            $repo->findBy(['title' => 'Counted']),
            fn (Task $t) => null === $t->getCompletedOn(),
        ));
        $this->assertCount(1, $pending, 'A count=2 series spawns exactly one successor.');
        $rule = $pending[0]->getRecurrenceRule();
        $this->assertNotNull($rule);
        $this->assertSame(['type' => 'count', 'count' => 1], $rule['ends'] ?? null);
    }

    public function testRecurrencePreviewReturnsNextOccurrences(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $response = $client->request('POST', '/recurrence-preview', [
            'json' => [
                'dueDate' => '2026-06-01T08:00:00+00:00',
                'rule' => ['frequency' => 'daily', 'interval' => 2],
                'count' => 3,
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseIsSuccessful();
        $body = $response->toArray();
        $occurrences = $body['occurrences'] ?? null;
        $this->assertIsArray($occurrences);
        $this->assertCount(3, $occurrences);
        $first = $occurrences[0];
        $second = $occurrences[1];
        $third = $occurrences[2];
        $this->assertIsString($first);
        $this->assertIsString($second);
        $this->assertIsString($third);
        $this->assertStringStartsWith('2026-06-03', $first);
        $this->assertStringStartsWith('2026-06-05', $second);
        $this->assertStringStartsWith('2026-06-07', $third);
    }

    public function testCreateTaskWithReminders(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Take medication',
                'dueDate' => '2026-06-01T08:00:00+00:00',
                'reminders' => [
                    ['type' => 'relative', 'value' => 1, 'unit' => 'hours', 'repeat' => false],
                    ['type' => 'relative', 'value' => 15, 'unit' => 'minutes', 'repeat' => false],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $task = $this->reloadTaskByTitle('Take medication');
        $this->assertSame(
            [
                ['type' => 'relative', 'value' => 1, 'unit' => 'hours', 'repeat' => false],
                ['type' => 'relative', 'value' => 15, 'unit' => 'minutes', 'repeat' => false],
            ],
            $task->getReminders(),
        );
    }

    public function testCreateTaskWithAbsoluteReminderNeedsNoDueDate(): void
    {
        // Absolute reminders fire on their own clock, so they don't require
        // a due date the way relative reminders do.
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Standalone absolute',
                'reminders' => [
                    ['type' => 'absolute', 'at' => '2026-06-01T09:00:00+00:00', 'repeat' => true],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
    }

    public function testRelativeRemindersWithoutDueDateAreStoredDormant(): void
    {
        // A relative reminder may be configured before a due date exists; it is
        // stored and stays dormant (never fires) until a due date anchors it.
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Orphan reminder',
                'reminders' => [
                    ['type' => 'relative', 'value' => 1, 'unit' => 'hours', 'repeat' => false],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $task = $this->reloadTaskByTitle('Orphan reminder');
        $this->assertSame(
            [['type' => 'relative', 'value' => 1, 'unit' => 'hours', 'repeat' => false]],
            $task->getReminders(),
        );
    }

    public function testInvalidReminderShapeIsRejected(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Bad unit',
                'dueDate' => '2026-06-01T08:00:00+00:00',
                'reminders' => [
                    ['type' => 'relative', 'value' => 2, 'unit' => 'fortnights', 'repeat' => false],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testDuplicateReminderIsRejected(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Dup reminder',
                'dueDate' => '2026-06-01T08:00:00+00:00',
                'reminders' => [
                    ['type' => 'relative', 'value' => 1, 'unit' => 'hours', 'repeat' => false],
                    ['type' => 'relative', 'value' => 1, 'unit' => 'hours', 'repeat' => true],
                ],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testEmptyRemindersArrayIsAllowedWithoutDueDate(): void
    {
        // Equivalent to "no reminders" — must not trip the dueDate-required
        // rule, otherwise users couldn't clear reminders before clearing
        // the due date.
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'No reminders',
                'reminders' => [],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $task = $this->reloadTaskByTitle('No reminders');
        $this->assertNull($task->getReminders());
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
        $this->assertSame(0, $repo->findOneBy(['title' => 'C'])?->getPosition());
        $this->assertSame(1, $repo->findOneBy(['title' => 'A'])?->getPosition());
        $this->assertSame(2, $repo->findOneBy(['title' => 'B'])?->getPosition());
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
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
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

    public function testReorderLetsProjectMemberReorderProjectTasks(): void
    {
        // Project members can reorder tasks attached to the project even
        // when they don't own them — anyone who can add a task to the
        // project can also reorder its tasks. Bob is the project owner
        // and creates a task on it; Alice is a member who reorders.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $project = new Project();
        $project->setOwner($bob);
        $project->setTitle('Shared');
        $this->addProjectMember($project, $alice);
        $this->addProjectMember($project, $bob);
        $this->entityManager->persist($project);

        $shared1 = new Task();
        $shared1->setOwner($bob);
        $shared1->setProject($project);
        $shared1->setTitle('Shared 1');
        $this->entityManager->persist($shared1);

        $shared2 = new Task();
        $shared2->setOwner($bob);
        $shared2->setProject($project);
        $shared2->setTitle('Shared 2');
        $this->entityManager->persist($shared2);

        $alicePersonal = $this->createTask($alice, 'Alice personal');

        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/reorder', [
            'json' => [
                'order' => [
                    '/tasks/' . $shared2->getId(),
                    '/tasks/' . $alicePersonal->getId(),
                    '/tasks/' . $shared1->getId(),
                ],
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(Task::class);
        $this->assertSame(0, $repo->findOneBy(['title' => 'Shared 2'])?->getPosition());
        $this->assertSame(1, $repo->findOneBy(['title' => 'Alice personal'])?->getPosition());
        $this->assertSame(2, $repo->findOneBy(['title' => 'Shared 1'])?->getPosition());
    }

    public function testReorderSilentlySkipsNonOwnedIris(): void
    {
        // Reorder is permissive about extra IRIs in the input — Bob's task
        // is silently ignored so the frontend doesn't have to know which
        // visible rows belong to the current user (admins, project members,
        // etc. can see tasks they don't own). Bob's `position` must stay
        // exactly where it was.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $aliceTask = $this->createTask($alice, 'Alice only');
        $bobsTask = $this->createTask($bob, 'Bob owns this');
        $bobsOriginalPosition = $bobsTask->getPosition();

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

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(Task::class);
        $this->assertSame(0, $repo->findOneBy(['title' => 'Alice only'])?->getPosition());
        $this->assertSame(
            $bobsOriginalPosition,
            $repo->findOneBy(['title' => 'Bob owns this'])?->getPosition(),
            "Bob's task position must not change when Alice reorders.",
        );
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

    public function testCreateTaskAcceptsAssigneeOwner(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Solo task',
                'assignees' => ['/users/' . $alice->getId()],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $task = $this->reloadTaskByTitle('Solo task');
        $this->assertCount(1, $task->getAssignees());
        $first = $task->getAssignees()->first();
        self::assertNotFalse($first);
        $this->assertTrue($alice->getId()?->equals($first->getId()));
    }

    public function testCreateTaskRejectsNonProjectMemberAssignee(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Personal but with bob',
                'assignees' => ['/users/' . $bob->getId()],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);

        // Personal task (no project) — only owner can be assigned. Bob is rejected.
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchAssigneesAcceptsProjectMembers(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Team task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => [
                'assignees' => [
                    '/users/' . $alice->getId(),
                    '/users/' . $bob->getId(),
                ],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(2, $reloaded->getAssignees());
    }

    public function testPatchAssigneesRejectsOutsideProject(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Team task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/tasks/' . $task->getId(), [
            'json' => ['assignees' => ['/users/' . $carol->getId()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testFilterTasksByAssignee(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');

        $aliceOnly = $this->createTaskInProject($alice, $project, 'Alice only');
        $aliceOnly->addAssignee($alice);
        $bothAssigned = $this->createTaskInProject($alice, $project, 'Both assigned');
        $bothAssigned->addAssignee($alice);
        $bothAssigned->addAssignee($bob);
        $unassigned = $this->createTaskInProject($alice, $project, 'Unassigned');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?assignees=/users/' . $bob->getId());

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['Both assigned'], $titles);
    }

    public function testAddAssigneeEndpoint(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Team task');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/' . $task->getId() . '/assignees', [
            'json' => ['userId' => (string) $bob->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(1, $reloaded->getAssignees());
        $first = $reloaded->getAssignees()->first();
        self::assertNotFalse($first);
        $this->assertTrue($bob->getId()?->equals($first->getId()));
    }

    public function testAddAssigneeEndpointRejectsDuplicate(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Personal');
        $task->addAssignee($alice);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/' . $task->getId() . '/assignees', [
            'json' => ['userId' => (string) $alice->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testAddAssigneeEndpointRejectsNonMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $task = $this->createTask($alice, 'Personal');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/' . $task->getId() . '/assignees', [
            'json' => ['userId' => (string) $bob->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        // Personal task — only Alice (owner) can be assigned. Bob is rejected.
        $this->assertResponseStatusCodeSame(422);
    }

    public function testRemoveAssigneeEndpoint(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = $this->createSharedProject($alice, [$alice, $bob], 'Team');
        $task = $this->createTaskInProject($alice, $project, 'Team task');
        $task->addAssignee($bob);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request(
            'DELETE',
            '/tasks/' . $task->getId() . '/assignees/' . $bob->getId(),
        );

        $this->assertResponseStatusCodeSame(204);
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Task::class)->find($task->getId());
        $this->assertNotNull($reloaded);
        $this->assertCount(0, $reloaded->getAssignees());
    }

    public function testAssigneeEndpointsHide404ForOtherUsersTask(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobTask = $this->createTask($bob, "Bob's task");

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/tasks/' . $bobTask->getId() . '/assignees', [
            'json' => ['userId' => (string) $alice->getId()],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testOverdueFilterReturnsOnlyIncompletePastDueTasks(): void
    {
        $alice = $this->createUser('alice@example.com');

        // 1. overdue (past due, incomplete)
        $overdue = $this->createTask($alice, 'Overdue task');
        $overdue->setDueDate(new \DateTimeImmutable('-2 days'));
        // 2. due in the future — must NOT match
        $future = $this->createTask($alice, 'Future task');
        $future->setDueDate(new \DateTimeImmutable('+2 days'));
        // 3. past due BUT completed — must NOT match
        $done = $this->createTask($alice, 'Past but done');
        $done->setDueDate(new \DateTimeImmutable('-2 days'));
        $done->setCompletedOn(new \DateTimeImmutable('-1 day'));
        // 4. no due date — must NOT match
        $this->createTask($alice, 'No due date');
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?overdue=true');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame(1, $body['totalItems']);
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $first = $members[0] ?? null;
        $this->assertIsArray($first);
        $this->assertSame('Overdue task', $first['title']);
    }

    public function testOverdueFilterCountOnlyMode(): void
    {
        // The navbar polls with `itemsPerPage=1` and reads `totalItems` to
        // avoid pulling the full collection just for the badge count. Lock in
        // that contract.
        $alice = $this->createUser('alice@example.com');

        for ($i = 0; $i < 3; ++$i) {
            $task = $this->createTask($alice, "Late {$i}");
            $task->setDueDate(new \DateTimeImmutable('-1 day'));
        }
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?overdue=true&itemsPerPage=1');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame(3, $body['totalItems']);
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
    }

    public function testOverdueFilterFalseIsNoOp(): void
    {
        // `overdue=false` is intentionally not the inverse — tasks with no due
        // date and tasks due in the future are very different things. Treat it
        // as "no filter" so the parameter has a single useful interpretation.
        $alice = $this->createUser('alice@example.com');
        $past = $this->createTask($alice, 'Past');
        $past->setDueDate(new \DateTimeImmutable('-1 day'));
        $this->createTask($alice, 'No due date');
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?overdue=false');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 2]);
    }

    public function testOverdueFilterRespectsAccessControl(): void
    {
        // Bob's overdue task must not show up in Alice's overdue count even
        // though TaskOwnerExtension is a separate concern — defense-in-depth.
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobOverdue = $this->createTask($bob, "Bob's overdue");
        $bobOverdue->setDueDate(new \DateTimeImmutable('-3 days'));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?overdue=true');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['totalItems' => 0]);
    }

    public function testAssignableUsersIncludesSelf(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/me/assignable-users');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $emails = array_map(
            static fn (mixed $u): mixed => is_array($u) ? $u['email'] ?? null : null,
            $members,
        );
        $this->assertSame(['alice@example.com'], $emails);
    }

    public function testAssignableUsersIncludesProjectTeammates(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $this->createSharedProject($alice, [$alice, $bob], 'Team');
        // Carol shares no project with alice — must not appear.

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/me/assignable-users');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $emails = array_map(
            static fn (mixed $u): mixed => is_array($u) ? $u['email'] ?? null : null,
            $members,
        );
        sort($emails);
        $this->assertSame(['alice@example.com', 'bob@example.com'], $emails);
        $this->assertNotContains('carol@example.com', $emails);
    }

    /**
     * Guards the cross-space information leak fixed alongside this test:
     * a user who is an effective member of a space the caller does NOT
     * belong to must never surface in the caller's assignable-users list.
     * The old controller did a raw `Project::findAll()` (unscoped by the
     * access extension), so every effective member of every space in the
     * instance leaked through.
     */
    public function testAssignableUsersExcludesMembersOfOtherSpaces(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');

        // Alice's own space, shared with Bob.
        $this->createSharedProject($alice, [$alice, $bob], 'Alice Team');
        // A separate space Alice has no part in — Carol + Dave only.
        $dave = $this->createUser('dave@example.com');
        $this->createSharedProject($carol, [$carol, $dave], 'Carol Team');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/me/assignable-users');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $members = $body['member'] ?? null;
        $this->assertIsArray($members);
        $emails = array_map(
            static fn (mixed $u): mixed => is_array($u) ? $u['email'] ?? null : null,
            $members,
        );
        sort($emails);
        $this->assertSame(['alice@example.com', 'bob@example.com'], $emails);
        $this->assertNotContains('carol@example.com', $emails);
        $this->assertNotContains('dave@example.com', $emails);
    }

    /**
     * @param User[] $members
     */
    private function createSharedProject(User $owner, array $members, string $title): Project
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

    private function createTaskInProject(User $owner, Project $project, string $title): Task
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

    private function createTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    private function createProject(User $owner, string $title): Project
    {
        $project = new Project();
        $project->setOwner($owner);
        $project->setTitle($title);
        $this->addProjectMember($project, $owner);
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
    }

    /**
     * @param list<string>|null $options
     */
    private function createCustomFieldDefinition(
        Project $project,
        string $name,
        string $type,
        ?array $options = null,
    ): CustomFieldDefinition {
        $field = new CustomFieldDefinition();
        $field->setProject($project);
        $field->setName($name);
        $field->setType($type);
        if (null !== $options) {
            $field->setOptions($options);
        }
        $this->entityManager->persist($field);
        $this->entityManager->flush();
        return $field;
    }

    private function seedCustomFieldValue(
        Task $task,
        CustomFieldDefinition $definition,
        mixed $value,
    ): CustomFieldValue {
        $cfv = new CustomFieldValue();
        $cfv->setTask($task);
        $cfv->setDefinition($definition);
        $cfv->setValue($value);
        $this->entityManager->persist($cfv);
        $this->entityManager->flush();
        return $cfv;
    }

    public function testSearchMatchesTitle(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createTask($alice, 'Plan launch checklist');
        $this->createTask($alice, 'Buy groceries');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=launch');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['Plan launch checklist'], $titles);
    }

    public function testSearchMatchesDescription(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = new Task();
        $task->setOwner($alice);
        $task->setTitle('Misc');
        $task->setDescription('Need to follow up on the moonshot proposal.');
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=moonshot');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
    }

    public function testSearchMatchesCommentBody(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = $this->createTask($alice, 'Misc');
        $other = $this->createTask($alice, 'Other');

        $comment = new Comment();
        $comment->setTask($task);
        $comment->setAuthor($alice);
        $comment->setBody('We discussed the moonshot rollout in standup.');
        $this->entityManager->persist($comment);
        $this->entityManager->flush();
        $this->assertNotNull($other->getId());

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=moonshot');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['Misc'], $titles);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createTask($alice, 'Plan LAUNCH checklist');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=launch');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(1, $members);
    }

    public function testSearchEscapesLikeWildcards(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createTask($alice, 'Plan launch');
        $this->createTask($alice, '50% off promo');

        $client = static::createClient();
        $client->loginUser($alice);
        // The literal `%` in the query must not match every row — the
        // filter has to escape LIKE wildcards or this returns 2.
        $client->request('GET', '/tasks?search=' . urlencode('50%'));

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['50% off promo'], $titles);
    }

    public function testEmptySearchIsNoOp(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createTask($alice, 'A');
        $this->createTask($alice, 'B');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=' . urlencode('   '));

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(2, $members);
    }

    public function testSearchRespectsTaskScope(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->createTask($alice, 'Alice launch plan');
        $this->createTask($bob, 'Bob launch plan');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=launch');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['Alice launch plan'], $titles);
    }

    public function testSearchRanksTitleAboveDescription(): void
    {
        $alice = $this->createUser('alice@example.com');

        // Title-only hit (created first → would lose the createdOn
        // tie-break without proper ranking)
        $titleHit = new Task();
        $titleHit->setOwner($alice);
        $titleHit->setTitle('Schema migration plan');
        $this->entityManager->persist($titleHit);
        $this->entityManager->flush();

        $descriptionHit = new Task();
        $descriptionHit->setOwner($alice);
        $descriptionHit->setTitle('Random task');
        $descriptionHit->setDescription('We need to think about migration.');
        $this->entityManager->persist($descriptionHit);
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=migration');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        // Title weight A > description weight B, so the title hit must
        // come first regardless of insertion order.
        $this->assertSame(['Schema migration plan', 'Random task'], $titles);
    }

    public function testSearchMatchesTextCustomFieldValue(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backlog');
        $hit = $this->createTaskInProject($alice, $project, 'Triage');
        $miss = $this->createTaskInProject($alice, $project, 'Buy groceries');
        $this->assertNotNull($miss->getId());

        $severity = $this->createCustomFieldDefinition(
            $project,
            'Severity',
            CustomFieldDefinition::TYPE_TEXT,
        );
        $this->seedCustomFieldValue($hit, $severity, 'moonshot blocker');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=moonshot');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['Triage'], $titles);
    }

    public function testSearchMatchesDropdownCustomFieldValue(): void
    {
        $alice = $this->createUser('alice@example.com');
        $project = $this->createProject($alice, 'Backlog');
        $hit = $this->createTaskInProject($alice, $project, 'Login redesign');
        $this->createTaskInProject($alice, $project, 'Cleanup');

        $stage = $this->createCustomFieldDefinition(
            $project,
            'Stage',
            CustomFieldDefinition::TYPE_DROPDOWN,
            ['discovery', 'in-review', 'shipped'],
        );
        $this->seedCustomFieldValue($hit, $stage, 'in-review');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?search=review');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['Login redesign'], $titles);
    }

    public function testStatusFilterOpen(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createTask($alice, 'Open one');
        $done = $this->createTask($alice, 'Done one');
        $done->setCompletedOn(new \DateTimeImmutable());
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?status=open');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['Open one'], $titles);
    }

    public function testStatusFilterCompleted(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createTask($alice, 'Open');
        $done = $this->createTask($alice, 'Done');
        $done->setCompletedOn(new \DateTimeImmutable());
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?status=completed');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['Done'], $titles);
    }

    public function testStatusFilterIgnoresUnknownValue(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createTask($alice, 'A');
        $this->createTask($alice, 'B');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?status=banana');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $this->assertCount(2, $members);
    }

    public function testDueDateRangeFilter(): void
    {
        $alice = $this->createUser('alice@example.com');
        $earlier = $this->createTask($alice, 'Earlier');
        $earlier->setDueDate(new \DateTimeImmutable('2026-04-01'));
        $inRange = $this->createTask($alice, 'In window');
        $inRange->setDueDate(new \DateTimeImmutable('2026-05-15'));
        $later = $this->createTask($alice, 'Later');
        $later->setDueDate(new \DateTimeImmutable('2026-06-30'));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request(
            'GET',
            '/tasks?dueDate%5Bafter%5D=2026-05-01&dueDate%5Bbefore%5D=2026-05-31',
        );

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['In window'], $titles);
    }

    public function testOrderByDueDateAsc(): void
    {
        $alice = $this->createUser('alice@example.com');
        $a = $this->createTask($alice, 'A');
        $a->setDueDate(new \DateTimeImmutable('2026-06-01'));
        $b = $this->createTask($alice, 'B');
        $b->setDueDate(new \DateTimeImmutable('2026-05-01'));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/tasks?order%5BdueDate%5D=asc');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $members = $response->toArray()['member'] ?? null;
        $this->assertIsArray($members);
        $titles = array_map(
            static fn (mixed $t): mixed => is_array($t) ? $t['title'] ?? null : null,
            $members,
        );
        $this->assertSame(['B', 'A'], $titles);
    }

    private function reloadTaskByTitle(string $title): Task
    {
        $this->entityManager->clear();
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['title' => $title]);
        self::assertNotNull($task, sprintf('Expected to find Task with title "%s".', $title));
        return $task;
    }
}
