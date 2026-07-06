<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Calendar\CalendarSyncException;
use App\Entity\CalendarEventLink;
use App\Entity\OAuthConnection;
use App\Entity\Task;
use App\Entity\User;
use App\Tests\Calendar\InMemoryCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Push-sync handler branches (#582) the happy-path test doesn't hit: removing
 * the due date deletes the event, and an update that finds the event gone
 * recreates it.
 */
class SyncTaskToCalendarBranchesTest extends ApiTestCase
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

    public function testRemovingDueDateDeletesEvent(): void
    {
        $user = $this->createUser('rm@example.com');
        $this->connectGoogle($user);
        $client = static::createClient();
        $client->getKernelBrowser()->disableReboot();
        $client->loginUser($user);

        $iri = $this->pushTask($client, 'Drop it', '2026-08-15T00:00:00+00:00');
        $this->assertSame(1, $this->linkCount());
        $this->calendar()->reset();

        $client->request('PATCH', $iri, [
            'json' => ['dueDate' => null],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        $this->assertSame(0, $this->linkCount());
        $ops = array_column($this->calendar()->calls, 'op');
        $this->assertContains('delete', $ops);
    }

    public function testUpdateOnGoneEventRecreates(): void
    {
        $user = $this->createUser('gone@example.com');
        $this->connectGoogle($user);
        $client = static::createClient();
        $client->getKernelBrowser()->disableReboot();
        $client->loginUser($user);

        $iri = $this->pushTask($client, 'Recreate me', '2026-08-15T00:00:00+00:00');
        // The next call (the update) reports the event vanished on the provider;
        // don't reset() so the recreated event gets a distinct id.
        $this->calendar()->failNext = CalendarSyncException::gone('gone');

        $client->request('PATCH', $iri, [
            'json' => ['title' => 'Recreated'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Stale link dropped + a fresh event created → one link, new event id.
        $this->entityManager->clear();
        $link = $this->entityManager->getRepository(CalendarEventLink::class)->findOneBy(['provider' => 'google']);
        $this->assertNotNull($link);
        $this->assertNotSame('evt-1', $link->getExternalEventId());
    }

    public function testDeletingTaskDeletesEvent(): void
    {
        $user = $this->createUser('del@example.com');
        $this->connectGoogle($user);
        $client = static::createClient();
        $client->getKernelBrowser()->disableReboot();
        $client->loginUser($user);

        $iri = $this->pushTask($client, 'Delete task', '2026-08-15T00:00:00+00:00');
        $this->calendar()->reset();

        $client->request('DELETE', $iri);
        $this->assertResponseStatusCodeSame(204);

        // TaskDeleteProcessor snapshots the event id then the handler deletes it.
        $ops = array_column($this->calendar()->calls, 'op');
        $this->assertContains('delete', $ops);
        $this->assertSame(0, $this->linkCount());
    }

    private function pushTask(Client $client, string $title, string $dueDate): string
    {
        $body = $client->request('POST', '/tasks', [
            'json' => ['title' => $title, 'dueDate' => $dueDate],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $iri = $body['@id'] ?? '';
        $this->assertIsString($iri);

        return $iri;
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
            ->setAccessToken('enc')
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
        $user->setGivenName('T');
        $user->setFamilyName('U');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, 'Password123!@#'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
