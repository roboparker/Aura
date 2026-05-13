<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserPreferencesTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')->getManager();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testGetRequiresAuth(): void
    {
        static::createClient()->request('GET', '/me/preferences');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetReturnsDefaultsWhenUnset(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/me/preferences');

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(User::DEFAULT_PREFERENCES);
    }

    public function testPatchUpdatesIndividualKeys(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['theme' => 'dark'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();
        $this->assertSame('dark', $body['theme']);
        // Other keys untouched.
        $this->assertTrue($body['emailNotificationsEnabled']);
        $this->assertSame('realtime', $body['notificationFrequency']);
    }

    public function testPatchUpdatesMultipleKeysAtOnce(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => [
                'theme' => 'light',
                'pushNotificationsEnabled' => true,
                'notificationFrequency' => 'daily',
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();
        $this->assertSame('light', $body['theme']);
        $this->assertTrue($body['pushNotificationsEnabled']);
        $this->assertSame('daily', $body['notificationFrequency']);
    }

    public function testPatchPersistsAcrossRequests(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['theme' => 'dark'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Fresh request — value survives a roundtrip through the database.
        $client->request('GET', '/me/preferences');
        $this->assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();
        $this->assertSame('dark', $body['theme']);
    }

    public function testPatchRejectsUnknownKey(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['language' => 'eo'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchRejectsInvalidThemeValue(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['theme' => 'sepia'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchRejectsInvalidFrequencyValue(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['notificationFrequency' => 'monthly'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchRejectsNonBooleanForToggle(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['emailNotificationsEnabled' => 'yes'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchRejectsNonObjectBody(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['dark', 'realtime'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        // Top-level array (rather than object) — caller error, 400.
        $this->assertResponseStatusCodeSame(400);
    }

    public function testApiMeReturnsPreferencesInline(): void
    {
        // The PWA reads preferences off the existing /api/me payload so the
        // theme can apply on first paint without a second round-trip.
        $alice = $this->createUser('alice@example.com');
        $alice->setPreferences(array_merge(User::DEFAULT_PREFERENCES, ['theme' => 'dark']));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/api/me');

        $this->assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();
        $this->assertArrayHasKey('preferences', $body);
        $this->assertSame('dark', $body['preferences']['theme']);
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
