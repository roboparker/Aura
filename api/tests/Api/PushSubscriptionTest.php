<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PushSubscriptionTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')->getManager();

        $this->entityManager->createQuery('DELETE FROM App\Entity\PushSubscription')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testListRequiresAuth(): void
    {
        static::createClient()->request('GET', '/me/push-subscriptions');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateRegistersForCurrentUser(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/me/push-subscriptions', [
            'json' => [
                'endpoint' => 'https://fcm.googleapis.com/send/abc-alice',
                'p256dh' => 'BAseAseAseAse',
                'auth' => 'AuthAlice123',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $rows = $this->entityManager->getRepository(PushSubscription::class)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame(
            (string) $alice->getId(),
            (string) $rows[0]->getUser()?->getId(),
        );
    }

    public function testKeysAreNotEchoedInResponse(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/me/push-subscriptions', [
            'json' => [
                'endpoint' => 'https://fcm.googleapis.com/send/secret-keys',
                'p256dh' => 'SECRET-PUBKEY',
                'auth' => 'SECRET-AUTH',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        $this->assertResponseStatusCodeSame(201);

        $body = (string) $client->getResponse()->getContent();
        $this->assertStringNotContainsString('SECRET-PUBKEY', $body);
        $this->assertStringNotContainsString('SECRET-AUTH', $body);
    }

    public function testListReturnsOnlyOwnSubscriptions(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->seed($alice, 'https://fcm.example/a');
        $this->seed($bob, 'https://fcm.example/b');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/me/push-subscriptions');

        $this->assertResponseIsSuccessful();
        $endpoints = array_map(
            fn ($s) => $s['endpoint'],
            $client->getResponse()->toArray()['member'] ?? [],
        );
        $this->assertSame(['https://fcm.example/a'], $endpoints);
    }

    public function testCannotDeleteAnotherUsersSubscription(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $bobsSub = $this->seed($bob, 'https://fcm.example/bob');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request(
            'DELETE',
            '/me/push-subscriptions/' . $bobsSub->getId(),
        );
        // Item-level extension scopes the lookup to alice, so bob's
        // subscription appears not-found rather than 403 — same shape
        // as the rest of the per-user resources.
        $this->assertResponseStatusCodeSame(404);

        $this->entityManager->clear();
        $this->assertNotNull(
            $this->entityManager->getRepository(PushSubscription::class)
                ->find($bobsSub->getId()),
        );
    }

    public function testOwnerCanDeleteOwnSubscription(): void
    {
        $alice = $this->createUser('alice@example.com');
        $sub = $this->seed($alice, 'https://fcm.example/alice');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('DELETE', '/me/push-subscriptions/' . $sub->getId());
        $this->assertResponseStatusCodeSame(204);
    }

    public function testEndpointIsUnique(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->seed($alice, 'https://fcm.example/dup');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('POST', '/me/push-subscriptions', [
            'json' => [
                'endpoint' => 'https://fcm.example/dup',
                'p256dh' => 'X',
                'auth' => 'Y',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
        // Either the validator (UniqueEntity) or the DB unique index
        // catches it; both shapes are acceptable. Reject anything in
        // the 2xx range.
        $this->assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
    }

    public function testMultipleDevicesAllowedPerUser(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        foreach (['device-a', 'device-b', 'device-c'] as $i => $tag) {
            $client->request('POST', '/me/push-subscriptions', [
                'json' => [
                    'endpoint' => 'https://fcm.example/' . $tag,
                    'p256dh' => 'k' . $i,
                    'auth' => 'a' . $i,
                ],
                'headers' => ['Content-Type' => 'application/ld+json'],
            ]);
            $this->assertResponseStatusCodeSame(201);
        }
        $this->assertCount(
            3,
            $this->entityManager->getRepository(PushSubscription::class)->findAll(),
        );
    }

    private function seed(User $user, string $endpoint): PushSubscription
    {
        $sub = new PushSubscription();
        $sub->setUser($user);
        $sub->setEndpoint($endpoint);
        $sub->setP256dh('p256dh');
        $sub->setAuth('auth');
        $this->entityManager->persist($sub);
        $this->entityManager->flush();
        return $sub;
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
