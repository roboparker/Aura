<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Calendar\CalendarChange;
use App\Entity\OAuthConnection;
use App\Entity\Task;
use App\Entity\User;
use App\Service\CalendarPuller;
use App\Tests\Calendar\InMemoryCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Google → Aura pull (#582 Phase B): reflect provider-side moves/deletions of
 * Aura-managed events back onto their tasks. A task is first pushed through the
 * normal create path (recording an event id on the in-memory client), then the
 * client is armed with changes and {@see CalendarPuller} is run.
 */
class CalendarPullTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\CalendarEventLink')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\OAuthConnection')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRescheduleOnGoogleMovesTaskDueDate(): void
    {
        $this->pushDatedTask('Reschedule me', '2026-08-15T09:00:00+00:00');

        $calendar = $this->calendar();
        $calendar->pendingChanges = [new CalendarChange('evt-1', false, new \DateTimeImmutable('2026-08-20'))];
        $this->runPull();

        $task = $this->reloadTask('Reschedule me');
        $this->assertNotNull($task->getDueDate());
        $this->assertSame('2026-08-20', $task->getDueDate()->format('Y-m-d'));
        // Time-of-day is preserved through the reflected move.
        $this->assertSame('09:00', $task->getDueDate()->format('H:i'));
        // Cursor persisted for the next incremental pass.
        $this->assertSame('sync-1', $this->reloadConnection()->getCalendarSyncToken());
    }

    public function testCancelOnGoogleClearsDueDateAndUnlinks(): void
    {
        $this->pushDatedTask('Delete me', '2026-08-15T00:00:00+00:00');
        $this->assertSame(1, $this->linkCount());

        $this->calendar()->pendingChanges = [new CalendarChange('evt-1', true, null)];
        $this->runPull();

        $task = $this->reloadTask('Delete me');
        $this->assertNull($task->getDueDate());
        $this->assertSame(0, $this->linkCount());
    }

    public function testUnknownEventIsIgnored(): void
    {
        $this->pushDatedTask('Keep me', '2026-08-15T00:00:00+00:00');

        $this->calendar()->pendingChanges = [new CalendarChange('evt-other', false, new \DateTimeImmutable('2026-09-01'))];
        $this->runPull();

        $task = $this->reloadTask('Keep me');
        $this->assertNotNull($task->getDueDate());
        $this->assertSame('2026-08-15', $task->getDueDate()->format('Y-m-d'));
        $this->assertSame(1, $this->linkCount());
    }

    public function testSameDateIsANoOp(): void
    {
        $this->pushDatedTask('Unchanged', '2026-08-15T00:00:00+00:00');

        $this->calendar()->pendingChanges = [new CalendarChange('evt-1', false, new \DateTimeImmutable('2026-08-15'))];
        $this->runPull();

        $task = $this->reloadTask('Unchanged');
        $this->assertSame('2026-08-15', $task->getDueDate()?->format('Y-m-d'));
        $this->assertSame(1, $this->linkCount());
    }

    public function testStaleTokenTriggersFullResync(): void
    {
        $this->pushDatedTask('Resync me', '2026-08-15T00:00:00+00:00');

        // Pretend a cursor already exists and the provider rejects it.
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->reloadConnection()->setCalendarSyncToken('stale-cursor');
        $em->flush();

        $calendar = $this->calendar();
        $calendar->reportTokenInvalid = true;
        $calendar->pendingChanges = [new CalendarChange('evt-1', false, new \DateTimeImmutable('2026-08-25'))];
        $this->runPull();

        $task = $this->reloadTask('Resync me');
        $this->assertSame('2026-08-25', $task->getDueDate()?->format('Y-m-d'));
        $this->assertSame('sync-1', $this->reloadConnection()->getCalendarSyncToken());
    }

    private function pushDatedTask(string $title, string $dueDate): void
    {
        $user = $this->createUser($title . '@example.com');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => $title, 'dueDate' => $dueDate],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        // The push recorded a create → the link now carries event id 'evt-1'.
        $this->calendar()->reset();
    }

    private function runPull(): void
    {
        $puller = static::getContainer()->get(CalendarPuller::class);
        $puller->pull($this->reloadConnection());
    }

    private function calendar(): InMemoryCalendarClient
    {
        return static::getContainer()->get(InMemoryCalendarClient::class);
    }

    /**
     * The connection managed by the *current* container's EM — the same one the
     * puller flushes through (createClient() reboots the kernel, so setUp's EM
     * is a different instance).
     */
    private function reloadConnection(): OAuthConnection
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $connection = $em->getRepository(OAuthConnection::class)->findOneBy(['provider' => 'google']);
        assert($connection instanceof OAuthConnection);

        return $connection;
    }

    private function reloadTask(string $title): Task
    {
        $this->entityManager->clear();
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['title' => $title]);
        assert($task instanceof Task);

        return $task;
    }

    private function linkCount(): int
    {
        $this->entityManager->clear();

        return (int) $this->entityManager
            ->createQuery('SELECT COUNT(l.id) FROM App\Entity\CalendarEventLink l')
            ->getSingleScalarResult();
    }

    private function connectGoogle(User $user): void
    {
        $connection = (new OAuthConnection('google'))
            ->setUser($user)
            ->setEmail($user->getEmail())
            ->setAccessToken('enc-token')
            ->setExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['https://www.googleapis.com/auth/calendar']);
        $this->entityManager->persist($connection);
        $this->entityManager->flush();
    }

    private function createUser(string $email): User
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
}
