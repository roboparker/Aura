<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Calendar\CalendarChange;
use App\Calendar\CalendarEvent;
use App\Entity\OAuthConnection;
use App\Entity\Task;
use App\Entity\User;
use App\Service\CalendarPuller;
use App\Tests\Calendar\InMemoryCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Calendar sync hardening (#582 Phase D): timezone-aware events (D1) and the
 * pull conflict guard (D2).
 */
class CalendarHardeningTest extends ApiTestCase
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

    public function testTaskWithTimeBecomesTimedEventInOwnerZone(): void
    {
        $user = $this->createUser('timed@example.com', 'America/New_York');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Standup', 'dueDate' => '2026-08-15T14:30:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $event = $this->pushedEvent();
        $this->assertFalse($event->allDay);
        $this->assertSame('America/New_York', $event->timeZone);
    }

    public function testMidnightTaskStaysAllDay(): void
    {
        $user = $this->createUser('allday@example.com', 'America/New_York');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Whole day', 'dueDate' => '2026-08-15T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertTrue($this->pushedEvent()->allDay);
    }

    public function testStaleProviderChangeDoesNotClobberFresherAuraEdit(): void
    {
        $this->pushDatedTask('Guarded', '2026-08-15T00:00:00+00:00');

        // Provider reports a move, but stamped *before* our last push.
        $this->calendar()->pendingChanges = [
            new CalendarChange('evt-1', false, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('-1 hour')),
        ];
        $this->runPull();

        // Aura's value wins — the stale echo is ignored.
        $this->assertSame('2026-08-15', $this->reloadTask('Guarded')->getDueDate()?->format('Y-m-d'));
    }

    public function testNewerProviderChangeIsReflected(): void
    {
        $this->pushDatedTask('Followed', '2026-08-15T00:00:00+00:00');

        $this->calendar()->pendingChanges = [
            new CalendarChange('evt-1', false, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('+1 hour')),
        ];
        $this->runPull();

        $this->assertSame('2026-09-01', $this->reloadTask('Followed')->getDueDate()?->format('Y-m-d'));
    }

    private function pushedEvent(): CalendarEvent
    {
        $calendar = $this->calendar();
        $event = $calendar->events['evt-1'] ?? null;
        assert($event instanceof CalendarEvent);

        return $event;
    }

    private function pushDatedTask(string $title, string $dueDate): void
    {
        $user = $this->createUser($title . '@example.com', 'UTC');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => $title, 'dueDate' => $dueDate],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->calendar()->reset();
    }

    private function runPull(): void
    {
        $puller = static::getContainer()->get(CalendarPuller::class);
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $connection = $em->getRepository(OAuthConnection::class)->findOneBy(['provider' => 'google']);
        assert($connection instanceof OAuthConnection);
        $puller->pull($connection);
    }

    private function calendar(): InMemoryCalendarClient
    {
        return static::getContainer()->get(InMemoryCalendarClient::class);
    }

    private function reloadTask(string $title): Task
    {
        $this->entityManager->clear();
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['title' => $title]);
        assert($task instanceof Task);

        return $task;
    }

    private function connectGoogle(User $user): void
    {
        $connection = (new OAuthConnection('google'))
            ->setUser($user)
            ->setEmail($user->getEmail())
            ->setAccessToken('enc-token')
            ->setExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['calendar']);
        $this->entityManager->persist($connection);
        $this->entityManager->flush();
    }

    private function createUser(string $email, string $timezone): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $user->setPreferences(['timezone' => $timezone]);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
