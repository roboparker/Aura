<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Calendar\CalendarEvent;
use App\Entity\CalendarEventLink;
use App\Entity\OAuthConnection;
use App\Entity\Task;
use App\Entity\User;
use App\Tests\Calendar\InMemoryCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Native recurring-event push + spawn reconciliation (#582 Phase D4). A
 * recurring task pushes one recurring event; completing it spawns the next
 * occurrence, which *inherits* the event link so the series moves forward
 * instead of a duplicate series being created.
 */
class RecurringCalendarSyncTest extends ApiTestCase
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

    public function testRecurringTaskPushesRecurringEvent(): void
    {
        $user = $this->createUser('rec@example.com');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Daily standup',
                'dueDate' => '2026-08-15T00:00:00+00:00',
                'recurrenceRule' => ['frequency' => 'daily', 'interval' => 1],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $event = $this->calendar()->events['evt-1'] ?? null;
        assert($event instanceof CalendarEvent);
        $this->assertNotNull($event->recurrenceRule);
        $this->assertSame(1, $this->linkCount());
    }

    public function testCompletingRecurringTaskTransfersLinkToSuccessor(): void
    {
        $user = $this->createUser('rec2@example.com');
        $this->connectGoogle($user);

        $client = static::createClient();
        $client->getKernelBrowser()->disableReboot();
        $client->loginUser($user);
        $create = $client->request('POST', '/tasks', [
            'json' => [
                'title' => 'Daily thing',
                'dueDate' => '2026-08-15T00:00:00+00:00',
                'recurrenceRule' => ['frequency' => 'daily', 'interval' => 1],
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $iri = $create->toArray()['@id'];
        $this->assertIsString($iri);
        $this->assertSame(1, $this->linkCount());

        $this->calendar()->reset();
        $client->request('PATCH', $iri, [
            'json' => ['completedOn' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        // The series moved forward: one event updated, not a second created.
        $calls = $this->calendar()->calls;
        $this->assertCount(1, $calls);
        $this->assertSame('update', $calls[0]['op']);

        // Still exactly one link, and it now belongs to the incomplete successor.
        $this->assertSame(1, $this->linkCount());
        $link = $this->onlyLink();
        $this->assertNull($link->getTask()?->getCompletedOn());
    }

    private function onlyLink(): CalendarEventLink
    {
        $this->entityManager->clear();
        $links = $this->entityManager->getRepository(CalendarEventLink::class)->findAll();
        $this->assertCount(1, $links);

        return $links[0];
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
