<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Calendar\CalendarChange;
use App\Entity\CalendarSubscription;
use App\Entity\OAuthConnection;
use App\Entity\Task;
use App\Entity\User;
use App\Tests\Calendar\InMemoryCalendarClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Provider change-notification webhooks (#582 Phase D). The receiver verifies
 * the per-subscription secret and dispatches a targeted pull; in the test env
 * that pull runs inline (sync messenger), so an armed change reflects onto the
 * task — proving the webhook → pull path end to end.
 */
class CalendarWebhookTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\CalendarSubscription')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CalendarEventLink')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\OAuthConnection')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Task')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testMicrosoftValidationHandshakeEchoesToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/calendar/webhook/microsoft?validationToken=hello-123');

        $this->assertResponseIsSuccessful();
        $this->assertSame('hello-123', $client->getKernelBrowser()->getResponse()->getContent());
    }

    public function testGoogleNotificationWithValidTokenTriggersPull(): void
    {
        $user = $this->createUser('g@example.com');
        $connection = $this->connect($user, 'google');
        $this->subscribe($connection, 'chan-test', 'topsecret');

        $client = static::createClient();
        // Keep one kernel across the push + webhook requests so the in-memory
        // client we arm below is the one the (sync) pull handler reads.
        $client->getKernelBrowser()->disableReboot();
        $client->loginUser($user);
        $this->pushDatedTask($client, 'Webhook move', '2026-08-15T00:00:00+00:00');

        $this->calendar()->pendingChanges = [new CalendarChange('evt-1', false, new \DateTimeImmutable('2026-08-21'))];
        $client->request('POST', '/calendar/webhook/google', ['headers' => [
            'X-Goog-Resource-State' => 'exists',
            'X-Goog-Channel-ID' => 'chan-test',
            'X-Goog-Channel-Token' => 'topsecret',
        ]]);
        $this->assertResponseStatusCodeSame(200);

        $this->assertSame('2026-08-21', $this->reloadTask('Webhook move')->getDueDate()?->format('Y-m-d'));
    }

    public function testGoogleNotificationWithWrongTokenIsIgnored(): void
    {
        $user = $this->createUser('gbad@example.com');
        $connection = $this->connect($user, 'google');
        $this->subscribe($connection, 'chan-test', 'topsecret');

        $client = static::createClient();
        $client->getKernelBrowser()->disableReboot();
        $client->loginUser($user);
        $this->pushDatedTask($client, 'Unmoved', '2026-08-15T00:00:00+00:00');

        $this->calendar()->pendingChanges = [new CalendarChange('evt-1', false, new \DateTimeImmutable('2026-08-21'))];
        $client->request('POST', '/calendar/webhook/google', ['headers' => [
            'X-Goog-Resource-State' => 'exists',
            'X-Goog-Channel-ID' => 'chan-test',
            'X-Goog-Channel-Token' => 'WRONG',
        ]]);
        $this->assertResponseStatusCodeSame(200);

        // Secret mismatch → no pull → task unchanged.
        $this->assertSame('2026-08-15', $this->reloadTask('Unmoved')->getDueDate()?->format('Y-m-d'));
    }

    public function testMicrosoftNotificationWithValidStateTriggersPull(): void
    {
        $user = $this->createUser('ms@example.com');
        $connection = $this->connect($user, 'microsoft');
        $this->subscribe($connection, 'sub-abc', 'graphsecret');

        $client = static::createClient();
        $client->getKernelBrowser()->disableReboot();
        $client->loginUser($user);
        $this->pushDatedTask($client, 'Graph move', '2026-08-15T00:00:00+00:00');

        $this->calendar()->pendingChanges = [new CalendarChange('evt-1', false, new \DateTimeImmutable('2026-08-23'))];
        $client->request('POST', '/calendar/webhook/microsoft', [
            'json' => ['value' => [['subscriptionId' => 'sub-abc', 'clientState' => 'graphsecret']]],
        ]);
        $this->assertResponseStatusCodeSame(202);

        $this->assertSame('2026-08-23', $this->reloadTask('Graph move')->getDueDate()?->format('Y-m-d'));
    }

    private function pushDatedTask(Client $client, string $title, string $dueDate): void
    {
        $client->request('POST', '/tasks', [
            'json' => ['title' => $title, 'dueDate' => $dueDate],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);
        $this->calendar()->reset();
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

    private function subscribe(OAuthConnection $connection, string $channelId, string $secret): void
    {
        $subscription = (new CalendarSubscription($connection->getProvider()))
            ->setConnection($connection)
            ->setChannelId($channelId)
            ->setSecret($secret)
            ->setExpiresAt(new \DateTimeImmutable('+2 days'));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
    }

    private function connect(User $user, string $provider): OAuthConnection
    {
        $connection = (new OAuthConnection($provider))
            ->setUser($user)
            ->setEmail($user->getEmail())
            ->setAccessToken('enc-token')
            ->setExpiresAt(new \DateTimeImmutable('+1 hour'))
            ->setScopes(['calendar']);
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        return $connection;
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
