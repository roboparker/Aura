<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\CalendarEventLink;
use App\Entity\OAuthConnection;
use App\Entity\User;
use App\Tests\Calendar\InMemoryCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One-way Google Calendar push for dated tasks (#582, Phase A). The test env
 * routes messenger inline (sync://) and aliases the calendar client to an
 * in-memory recorder, so a task write drives the whole push synchronously and
 * we can inspect the recorded calls + the {@see CalendarEventLink} rows.
 */
class GoogleCalendarSyncTest extends ApiTestCase
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

    public function testCreatingDatedTaskPushesEvent(): void
    {
        $user = $this->createUser('a@example.com');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Submit report', 'dueDate' => '2026-08-15T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $calendar = $this->calendar();
        $this->assertCount(1, $calendar->calls);
        $this->assertSame('create', $calendar->calls[0]['op']);
        $this->assertSame('Submit report', $calendar->calls[0]['summary']);

        $this->assertSame(1, $this->linkCount());
    }

    public function testTaskWithoutDueDateDoesNotPush(): void
    {
        $user = $this->createUser('b@example.com');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'No due date'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertCount(0, $this->calendar()->calls);
        $this->assertSame(0, $this->linkCount());
    }

    public function testNoConnectionMeansNoPush(): void
    {
        $user = $this->createUser('c@example.com');
        // Deliberately not connected to any calendar account.

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Orphan task', 'dueDate' => '2026-08-15T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertCount(0, $this->calendar()->calls);
        $this->assertSame(0, $this->linkCount());
    }

    public function testUpdatingTaskUpdatesEvent(): void
    {
        $user = $this->createUser('d@example.com');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $create = $client->request('POST', '/tasks', [
            'json' => ['title' => 'Draft', 'dueDate' => '2026-08-15T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $iri = $create->toArray()['@id'];
        $this->assertIsString($iri);

        $this->calendar()->reset();
        $client->request('PATCH', $iri, [
            'json' => ['title' => 'Final'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $calendar = $this->calendar();
        $this->assertCount(1, $calendar->calls);
        $this->assertSame('update', $calendar->calls[0]['op']);
        $this->assertSame('Final', $calendar->calls[0]['summary']);
        // Still one link — updated in place, not duplicated.
        $this->assertSame(1, $this->linkCount());
    }

    public function testCompletingTaskMarksEventDone(): void
    {
        $user = $this->createUser('e@example.com');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $create = $client->request('POST', '/tasks', [
            'json' => ['title' => 'Ship it', 'dueDate' => '2026-08-15T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $iri = $create->toArray()['@id'];
        $this->assertIsString($iri);

        $this->calendar()->reset();
        $client->request('PATCH', $iri, [
            'json' => ['completedOn' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $calendar = $this->calendar();
        $this->assertCount(1, $calendar->calls);
        $this->assertSame('update', $calendar->calls[0]['op']);
        $this->assertStringStartsWith('✓', $calendar->calls[0]['summary']);
    }

    public function testDeletingTaskRemovesEvent(): void
    {
        $user = $this->createUser('f@example.com');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $create = $client->request('POST', '/tasks', [
            'json' => ['title' => 'Temporary', 'dueDate' => '2026-08-15T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $iri = $create->toArray()['@id'];
        $this->assertIsString($iri);
        $this->assertSame(1, $this->linkCount());

        $this->calendar()->reset();
        $client->request('DELETE', $iri);
        $this->assertResponseStatusCodeSame(204);

        $calendar = $this->calendar();
        $this->assertCount(1, $calendar->calls);
        $this->assertSame('delete', $calendar->calls[0]['op']);
        // The link CASCADE-deletes with the task.
        $this->assertSame(0, $this->linkCount());
    }

    public function testClearingDueDateRemovesEvent(): void
    {
        $user = $this->createUser('g@example.com');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $create = $client->request('POST', '/tasks', [
            'json' => ['title' => 'Was dated', 'dueDate' => '2026-08-15T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $iri = $create->toArray()['@id'];
        $this->assertIsString($iri);
        $this->assertSame(1, $this->linkCount());

        $this->calendar()->reset();
        $client->request('PATCH', $iri, [
            'json' => ['dueDate' => null],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $calendar = $this->calendar();
        $this->assertCount(1, $calendar->calls);
        $this->assertSame('delete', $calendar->calls[0]['op']);
        $this->assertSame(0, $this->linkCount());
    }

    private function calendar(): InMemoryCalendarClient
    {
        return static::getContainer()->get(InMemoryCalendarClient::class);
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

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

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
}
