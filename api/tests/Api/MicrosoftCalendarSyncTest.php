<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Calendar\CalendarChange;
use App\Entity\CalendarEventLink;
use App\Entity\OAuthConnection;
use App\Entity\Task;
use App\Entity\User;
use App\Service\CalendarPuller;
use App\Tests\Calendar\InMemoryCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Multi-provider calendar sync (#582 Phase C). The handler + puller are
 * provider-agnostic: they route through the registry, so a Microsoft connection
 * pushes/pulls through the same in-memory recorder as Google, and a user with
 * both connected gets one event per provider.
 */
class MicrosoftCalendarSyncTest extends ApiTestCase
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

    public function testMicrosoftConnectionPushesEvent(): void
    {
        $user = $this->createUser('ms@example.com');
        $this->connect($user, 'microsoft');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Graph task', 'dueDate' => '2026-08-15T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $calendar = $this->calendar();
        $this->assertCount(1, $calendar->calls);
        $this->assertSame('create', $calendar->calls[0]['op']);
        $this->assertSame('microsoft', $calendar->calls[0]['provider']);
        $this->assertSame(1, $this->linkCount('microsoft'));
    }

    public function testPushesToBothConnectedProviders(): void
    {
        $user = $this->createUser('both@example.com');
        $this->connect($user, 'google');
        $this->connect($user, 'microsoft');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Everywhere', 'dueDate' => '2026-08-15T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->assertCount(2, $this->calendar()->calls);
        $this->assertSame(1, $this->linkCount('google'));
        $this->assertSame(1, $this->linkCount('microsoft'));
    }

    public function testMicrosoftRescheduleReflectsOntoTask(): void
    {
        $user = $this->createUser('mspull@example.com');
        $this->connect($user, 'microsoft');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => ['title' => 'Move me', 'dueDate' => '2026-08-15T00:00:00+00:00'],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $calendar = $this->calendar();
        $calendar->reset();
        $calendar->pendingChanges = [new CalendarChange('evt-1', false, new \DateTimeImmutable('2026-08-22'))];

        $puller = static::getContainer()->get(CalendarPuller::class);
        $puller->pull($this->reloadConnection('microsoft'));

        $task = $this->reloadTask('Move me');
        $this->assertSame('2026-08-22', $task->getDueDate()?->format('Y-m-d'));
    }

    private function calendar(): InMemoryCalendarClient
    {
        return static::getContainer()->get(InMemoryCalendarClient::class);
    }

    private function reloadConnection(string $provider): OAuthConnection
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $connection = $em->getRepository(OAuthConnection::class)->findOneBy(['provider' => $provider]);
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

    private function linkCount(string $provider): int
    {
        $this->entityManager->clear();

        return (int) $this->entityManager
            ->createQuery('SELECT COUNT(l.id) FROM ' . CalendarEventLink::class . ' l WHERE l.provider = :p')
            ->setParameter('p', $provider)
            ->getSingleScalarResult();
    }

    private function connect(User $user, string $provider): void
    {
        $connection = (new OAuthConnection($provider))
            ->setUser($user)
            ->setEmail($user->getEmail())
            ->setAccessToken('enc-token')
            ->setExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['calendar']);
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
