<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Command\DispatchNotificationDigestCommand;
use App\Command\DispatchTaskRemindersCommand;
use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class NotificationTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        // Wipe the join tables before the entity tables so Postgres doesn't
        // bail on residual FK rows from a previous test class.
        $this->entityManager->createQuery('DELETE FROM App\Entity\Notification')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListNotificationsRequiresAuth(): void
    {
        static::createClient()->request('GET', '/notifications');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testListReturnsOnlyOwnNotifications(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $aliceNote = $this->seedNotification($alice, 'For Alice');
        $this->seedNotification($bob, 'For Bob');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/notifications');
        $this->assertResponseIsSuccessful();
        $titles = array_map(
            fn ($n) => $n['title'],
            $client->getResponse()->toArray()['member'] ?? [],
        );
        $this->assertSame(['For Alice'], $titles);
        $this->assertNotNull($aliceNote->getId());
    }

    public function testMarkAsRead(): void
    {
        $alice = $this->createUser('alice@example.com');
        $note = $this->seedNotification($alice, 'Reminder');
        $this->assertNull($note->getReadAt());

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/notifications/' . $note->getId(), [
            'json' => ['readAt' => '2026-05-03T08:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Notification::class)->find($note->getId());
        $this->assertNotNull($reloaded?->getReadAt());
    }

    public function testCannotPatchOtherUsersNotification(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $note = $this->seedNotification($bob, 'Bob only');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/notifications/' . $note->getId(), [
            'json' => ['readAt' => '2026-05-03T08:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        // Cross-recipient lookups return 404 (extension scopes the query).
        $this->assertResponseStatusCodeSame(404);
    }

    public function testFilterByUnread(): void
    {
        $alice = $this->createUser('alice@example.com');
        $unread = $this->seedNotification($alice, 'Unread');
        $read = $this->seedNotification($alice, 'Read');
        $read->setReadAt(new \DateTimeImmutable('2026-05-01T10:00:00+00:00'));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/notifications?exists[readAt]=false');
        $this->assertResponseIsSuccessful();
        $titles = array_map(
            fn ($n) => $n['title'],
            $client->getResponse()->toArray()['member'] ?? [],
        );
        $this->assertSame(['Unread'], $titles);
        $this->assertNotNull($unread->getId());
    }

    public function testDispatcherCreatesNotificationsForDueReminders(): void
    {
        $alice = $this->createUser('alice@example.com');
        // Due in 30 minutes — the 1h reminder window has already opened, so
        // the dispatcher should fire it. The 15m window has not.
        $task = new Task();
        $task->setOwner($alice);
        $task->setTitle('Standup');
        $task->setDueDate((new \DateTimeImmutable())->modify('+30 minutes'));
        $task->setReminders(['1h', '15m']);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $exitCode = $this->runDispatcher();
        $this->assertSame(0, $exitCode);

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(Notification::class)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame('1h', $rows[0]->getReminderOffset());
        $this->assertSame(Notification::TYPE_TASK_REMINDER, $rows[0]->getType());
    }

    public function testDispatcherIsIdempotent(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = new Task();
        $task->setOwner($alice);
        $task->setTitle('Standup');
        $task->setDueDate((new \DateTimeImmutable())->modify('+30 minutes'));
        $task->setReminders(['1h']);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $this->runDispatcher();
        $this->runDispatcher();

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(Notification::class)->findAll();
        $this->assertCount(1, $rows, 'Re-running dispatch must not duplicate notifications.');
    }

    public function testDispatcherSkipsCompletedTasks(): void
    {
        $alice = $this->createUser('alice@example.com');
        $task = new Task();
        $task->setOwner($alice);
        $task->setTitle('Already done');
        $task->setDueDate((new \DateTimeImmutable())->modify('+30 minutes'));
        $task->setReminders(['1h']);
        $task->setCompletedOn(new \DateTimeImmutable('-5 minutes'));
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $this->runDispatcher();
        $this->entityManager->clear();
        $this->assertCount(0, $this->entityManager->getRepository(Notification::class)->findAll());
    }

    public function testDispatcherCreatesOneRowPerAssignee(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $project = new \App\Entity\Project();
        $project->setOwner($alice);
        $project->setTitle('Team');
        $project->addMember($alice);
        $project->addMember($bob);
        $this->entityManager->persist($project);

        $task = new Task();
        $task->setOwner($alice);
        $task->setProject($project);
        $task->setTitle('Standup');
        $task->setDueDate((new \DateTimeImmutable())->modify('+30 minutes'));
        $task->setReminders(['1h']);
        $task->addAssignee($bob);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        $this->runDispatcher();

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(Notification::class)->findAll();
        // Owner (alice) + assignee (bob) — 2 rows.
        $this->assertCount(2, $rows);
        $emails = array_map(fn ($n) => $n->getRecipient()->getEmail(), $rows);
        sort($emails);
        $this->assertSame(['alice@example.com', 'bob@example.com'], $emails);
    }

    public function testDispatcherSendsEmailForRealtimeRecipient(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->seedDueTask($alice, 'Standup');

        $this->runDispatcher();

        $this->assertEmailCount(1);
        $message = $this->getMailerMessage(0);
        $this->assertEmailHeaderSame($message, 'To', 'alice@example.com');
        $this->assertEmailHeaderSame($message, 'Subject', 'Reminder: Standup');
        $this->assertEmailHtmlBodyContains($message, 'Standup');
        $this->assertEmailHtmlBodyContains($message, 'due in 1 hour');
        $this->assertEmailTextBodyContains($message, 'due in 1 hour');
    }

    public function testDispatcherSkipsEmailWhenUserDisabledIt(): void
    {
        $alice = $this->createUser('alice@example.com');
        $alice->setPreferences(['emailNotificationsEnabled' => false]);
        $this->entityManager->flush();
        $this->seedDueTask($alice, 'Standup');

        $this->runDispatcher();

        // The in-app row should still land — only the email is suppressed.
        $this->assertCount(
            1,
            $this->entityManager->getRepository(Notification::class)->findAll(),
        );
        $this->assertEmailCount(0);
    }

    public function testDispatcherSkipsEmailForDigestFrequencies(): void
    {
        $alice = $this->createUser('alice@example.com');
        $alice->setPreferences(['notificationFrequency' => 'hourly']);
        $bob = $this->createUser('bob@example.com');
        $bob->setPreferences(['notificationFrequency' => 'daily']);
        $this->entityManager->flush();

        $this->seedDueTask($alice, 'Alice task');
        $this->seedDueTask($bob, 'Bob task');

        $this->runDispatcher();

        $this->assertCount(
            2,
            $this->entityManager->getRepository(Notification::class)->findAll(),
        );
        // Both users are on digest frequencies — #102 owns those, the
        // realtime path here must stay quiet.
        $this->assertEmailCount(0);
    }

    public function testDispatcherDoesNotResendEmailOnIdempotentRerun(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->seedDueTask($alice, 'Standup');

        $this->runDispatcher();
        $this->runDispatcher();

        // Second run must skip the already-created notification AND the
        // already-sent email so cron retries don't spam recipients.
        $this->assertEmailCount(1);
    }

    private function seedDueTask(User $owner, string $title): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        $task->setDueDate((new \DateTimeImmutable())->modify('+30 minutes'));
        $task->setReminders(['1h']);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    public function testDigestGroupsPendingNotificationsForHourlyUser(): void
    {
        $alice = $this->createUser('alice@example.com');
        $alice->setPreferences(['notificationFrequency' => 'hourly']);
        $this->entityManager->flush();
        $this->seedNotification($alice, 'First');
        $this->seedNotification($alice, 'Second');

        $exit = $this->runDigest('hourly');
        $this->assertSame(0, $exit);

        // One email containing both items.
        $this->assertEmailCount(1);
        $msg = $this->getMailerMessage(0);
        $this->assertEmailHeaderSame($msg, 'To', 'alice@example.com');
        $this->assertEmailHeaderSame($msg, 'Subject', 'Hourly digest: 2 notifications waiting');
        $this->assertEmailHtmlBodyContains($msg, 'First');
        $this->assertEmailHtmlBodyContains($msg, 'Second');

        // Both rows are stamped so the next run skips them.
        $this->entityManager->clear();
        foreach ($this->entityManager->getRepository(Notification::class)->findAll() as $row) {
            $this->assertNotNull($row->getDigestedAt());
        }
    }

    public function testDigestRerunDoesNotResendAlreadyShipped(): void
    {
        $alice = $this->createUser('alice@example.com');
        $alice->setPreferences(['notificationFrequency' => 'daily']);
        $this->entityManager->flush();
        $this->seedNotification($alice, 'Yesterday');

        $this->runDigest('daily');
        $this->runDigest('daily');

        // Same notification, only one digest email — second run was a no-op.
        $this->assertEmailCount(1);
    }

    public function testDigestSkipsRealtimeUsers(): void
    {
        $alice = $this->createUser('alice@example.com');
        $alice->setPreferences(['notificationFrequency' => 'realtime']);
        $this->entityManager->flush();
        $this->seedNotification($alice, 'Realtime row');

        $this->runDigest('hourly');
        $this->runDigest('daily');

        $this->assertEmailCount(0);
    }

    public function testDigestRespectsEmailNotificationsDisabled(): void
    {
        $alice = $this->createUser('alice@example.com');
        $alice->setPreferences([
            'notificationFrequency' => 'hourly',
            'emailNotificationsEnabled' => false,
        ]);
        $this->entityManager->flush();
        $this->seedNotification($alice, 'Pending');

        $this->runDigest('hourly');

        $this->assertEmailCount(0);
        $this->entityManager->clear();
        // Row stays "undigested" so toggling email back on later still
        // gathers it into the next digest, rather than silently swallowing
        // the user's pending notifications during the opt-out window.
        $this->assertNull(
            $this->entityManager->getRepository(Notification::class)
                ->findOneBy(['title' => 'Pending'])
                ?->getDigestedAt(),
        );
    }

    public function testDigestRequiresValidPeriod(): void
    {
        $command = static::getContainer()->get(DispatchNotificationDigestCommand::class);
        $tester = new CommandTester($command);
        $this->assertSame(Command::INVALID, $tester->execute(['--period' => 'weekly']));
    }

    public function testDigestPeriodsDoNotPoachEachOthersUsers(): void
    {
        $alice = $this->createUser('alice@example.com');
        $alice->setPreferences(['notificationFrequency' => 'hourly']);
        $bob = $this->createUser('bob@example.com');
        $bob->setPreferences(['notificationFrequency' => 'daily']);
        $this->entityManager->flush();
        $this->seedNotification($alice, 'Alice item');
        $this->seedNotification($bob, 'Bob item');

        $this->runDigest('hourly');

        $this->assertEmailCount(1);
        $msg = $this->getMailerMessage(0);
        $this->assertEmailHeaderSame($msg, 'To', 'alice@example.com');
    }

    private function runDispatcher(): int
    {
        $command = static::getContainer()->get(DispatchTaskRemindersCommand::class);
        $tester = new CommandTester($command);
        return $tester->execute([]);
    }

    private function runDigest(string $period): int
    {
        $command = static::getContainer()->get(DispatchNotificationDigestCommand::class);
        $tester = new CommandTester($command);
        return $tester->execute(['--period' => $period]);
    }

    private function seedNotification(User $recipient, string $title): Notification
    {
        $note = new Notification();
        $note->setRecipient($recipient);
        $note->setTitle($title);
        $note->setType(Notification::TYPE_TASK_REMINDER);
        $this->entityManager->persist($note);
        $this->entityManager->flush();
        return $note;
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
}
