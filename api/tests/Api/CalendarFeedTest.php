<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Task;
use App\Entity\User;
use App\Ical\IcalWriter;
use App\Service\CalendarFeedTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers the read-only iCal calendar feed (#calendar-sync): token auth
 * (valid/invalid/rotated), feed contents (VEVENT/RRULE/VALARM), timezone
 * rendering, recurrence mapping vs expansion, and the self-service
 * /me/calendar-feed lifecycle endpoints.
 */
class CalendarFeedTest extends ApiTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM App\Entity\CalendarFeedToken')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testUnknownTokenReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/calendar/feed/deadbeefdeadbeef.ics');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testValidTokenServesTextCalendar(): void
    {
        $user = $this->createUser('feed@example.com');
        $this->createTask($user, 'Ship it', '2026-07-06T13:00:00+00:00');
        $token = $this->mintToken($user);

        $client = static::createClient();
        $response = $client->request('GET', $this->feedPath($token));

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');

        $body = $this->unfold($response->getContent());
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('SUMMARY:Ship it', $body);
        $this->assertStringContainsString('UID:task-', $body);
    }

    public function testFeedIncludesOwnedAndAssignedButNotForeignOrUndated(): void
    {
        $user = $this->createUser('me@example.com');
        $other = $this->createUser('other@example.com');

        $this->createTask($user, 'Owned due', '2026-07-06T13:00:00+00:00');
        $this->createTask($user, 'Owned no due', null);

        $assigned = $this->createTask($other, 'Assigned to me', '2026-07-07T13:00:00+00:00');
        $assigned->addAssignee($user);
        $this->createTask($other, 'Not mine', '2026-07-08T13:00:00+00:00');
        $this->em->flush();

        $body = $this->fetchFeed($user);

        $this->assertStringContainsString('SUMMARY:Owned due', $body);
        $this->assertStringContainsString('SUMMARY:Assigned to me', $body);
        $this->assertStringNotContainsString('Owned no due', $body);
        $this->assertStringNotContainsString('Not mine', $body);
    }

    public function testTimezoneRenderingUsesUserPreference(): void
    {
        $user = $this->createUser('ny@example.com', ['timezone' => 'America/New_York']);
        $this->createTask($user, 'Standup', '2026-07-06T13:00:00+00:00');

        $body = $this->fetchFeed($user);

        // 13:00 UTC in July = 09:00 EDT, emitted as local time with a TZID.
        $this->assertStringContainsString('BEGIN:VTIMEZONE', $body);
        $this->assertStringContainsString('TZID:America/New_York', $body);
        $this->assertStringContainsString('DTSTART;TZID=America/New_York:20260706T090000', $body);
    }

    public function testUtcUserGetsZuluTimesAndNoVtimezone(): void
    {
        $user = $this->createUser('utc@example.com', ['timezone' => 'UTC']);
        $this->createTask($user, 'Zulu', '2026-07-06T13:00:00+00:00');

        $body = $this->fetchFeed($user);

        $this->assertStringNotContainsString('BEGIN:VTIMEZONE', $body);
        $this->assertStringContainsString('DTSTART:20260706T130000Z', $body);
    }

    public function testWeeklyRecurringMapsToSingleRrule(): void
    {
        $user = $this->createUser('weekly@example.com', ['timezone' => 'UTC']);
        $task = $this->createTask($user, 'Weekly review', '2026-07-06T13:00:00+00:00');
        $task->setRecurrenceRule([
            'frequency' => 'weekly',
            'interval' => 1,
            'byDay' => ['MO'],
        ]);
        $this->em->flush();

        $body = $this->fetchFeed($user);

        $this->assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=MO', $body);
        $this->assertSame(1, substr_count($body, 'BEGIN:VEVENT'));
    }

    public function testMonthlyDay31ExpandsIntoMultipleEvents(): void
    {
        $user = $this->createUser('monthly@example.com', ['timezone' => 'UTC']);
        $task = $this->createTask($user, 'Rent', '2026-07-31T13:00:00+00:00');
        $task->setRecurrenceRule(['frequency' => 'monthly', 'interval' => 1]);
        $this->em->flush();

        $body = $this->fetchFeed($user);

        // Day-31 monthly can't be a single RRULE (clamp semantics), so it is
        // expanded into individual dated VEVENTs.
        $this->assertStringNotContainsString('RRULE:', $body);
        $this->assertGreaterThan(1, substr_count($body, 'BEGIN:VEVENT'));
    }

    public function testRelativeReminderBecomesValarm(): void
    {
        $user = $this->createUser('reminder@example.com', ['timezone' => 'UTC']);
        $task = $this->createTask($user, 'Call client', '2026-07-06T13:00:00+00:00');
        $task->setReminders([
            ['type' => 'relative', 'value' => 15, 'unit' => 'minutes', 'repeat' => false],
        ]);
        $this->em->flush();

        $body = $this->fetchFeed($user);

        $this->assertStringContainsString('BEGIN:VALARM', $body);
        $this->assertStringContainsString('ACTION:DISPLAY', $body);
        $this->assertStringContainsString('TRIGGER:-PT15M', $body);
    }

    public function testAbsoluteReminderBecomesUtcValarm(): void
    {
        $user = $this->createUser('abs@example.com', ['timezone' => 'UTC']);
        $task = $this->createTask($user, 'Launch', '2026-07-06T13:00:00+00:00');
        $task->setReminders([
            ['type' => 'absolute', 'at' => '2026-07-06T08:00:00+00:00', 'repeat' => false],
        ]);
        $this->em->flush();

        $body = $this->fetchFeed($user);

        $this->assertStringContainsString('TRIGGER;VALUE=DATE-TIME:20260706T080000Z', $body);
    }

    public function testRotatingTokenInvalidatesOldUrl(): void
    {
        $user = $this->createUser('rotate@example.com');
        $this->createTask($user, 'Task', '2026-07-06T13:00:00+00:00');

        $manager = $this->tokenManager();
        $first = $manager->regenerate($user);
        $second = $manager->regenerate($user);
        $this->assertNotSame($first, $second);

        $client = static::createClient();
        $client->request('GET', $this->feedPath($first));
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', $this->feedPath($second));
        $this->assertResponseIsSuccessful();
    }

    public function testSelfServiceLifecycle(): void
    {
        $user = $this->createUser('lifecycle@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        // Initially disabled.
        $response = $client->request('GET', '/me/calendar-feed');
        $this->assertResponseIsSuccessful();
        $this->assertFalse($response->toArray()['enabled']);

        // Regenerate returns a one-shot URL that actually resolves.
        $response = $client->request('POST', '/me/calendar-feed/regenerate');
        $this->assertResponseStatusCodeSame(201);
        $feedUrl = $response->toArray()['feedUrl'];
        $this->assertIsString($feedUrl);
        $this->assertStringContainsString('/calendar/feed/', $feedUrl);

        $response = $client->request('GET', '/me/calendar-feed');
        $this->assertTrue($response->toArray()['enabled']);

        // Revoke.
        $client->request('DELETE', '/me/calendar-feed');
        $this->assertResponseStatusCodeSame(204);

        $response = $client->request('GET', '/me/calendar-feed');
        $this->assertFalse($response->toArray()['enabled']);
    }

    public function testSelfServiceRequiresAuth(): void
    {
        static::createClient()->request('GET', '/me/calendar-feed');
        $this->assertResponseStatusCodeSame(401);
    }

    // --- helpers ---------------------------------------------------------

    private function fetchFeed(User $user): string
    {
        $token = $this->mintToken($user);
        $client = static::createClient();
        $response = $client->request('GET', $this->feedPath($token));
        $this->assertResponseIsSuccessful();

        return $this->unfold($response->getContent());
    }

    /** Rejoin folded continuation lines so property assertions are simple. */
    private function unfold(string $content): string
    {
        return str_replace(IcalWriter::CRLF . ' ', '', $content);
    }

    private function feedPath(string $token): string
    {
        return '/calendar/feed/' . $token . '.ics';
    }

    private function mintToken(User $user): string
    {
        return $this->tokenManager()->regenerate($user);
    }

    private function tokenManager(): CalendarFeedTokenManager
    {
        return static::getContainer()->get(CalendarFeedTokenManager::class);
    }

    /**
     * @param array<string, mixed> $preferences
     */
    private function createUser(string $email, array $preferences = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        if ([] !== $preferences) {
            $user->setPreferences($preferences);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createTask(User $owner, string $title, ?string $dueDate): Task
    {
        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle($title);
        if (null !== $dueDate) {
            $task->setDueDate(new \DateTimeImmutable($dueDate));
        }
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }
}
