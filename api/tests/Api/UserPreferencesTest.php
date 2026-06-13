<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class UserPreferencesTest extends ApiTestCase
{
    use JsonBodyAssertions;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;
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
            'json' => ['timezone' => 'America/New_York'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('America/New_York', $body['timezone']);
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
                'timezone' => 'America/New_York',
                'pushNotificationsEnabled' => true,
                'notificationFrequency' => 'daily',
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('America/New_York', $body['timezone']);
        $this->assertTrue($body['pushNotificationsEnabled']);
        $this->assertSame('daily', $body['notificationFrequency']);
    }

    public function testPatchPersistsAcrossRequests(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['timezone' => 'America/New_York'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();

        // Fresh request — value survives a roundtrip through the database.
        $client->request('GET', '/me/preferences');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('America/New_York', $body['timezone']);
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

    public function testCanBeImpersonatedDefaultsToFalse(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/me/preferences');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertFalse($response->toArray()['canBeImpersonated']);
    }

    public function testPatchTogglesCanBeImpersonated(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['canBeImpersonated' => true],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertTrue($response->toArray()['canBeImpersonated']);
    }

    public function testPatchRejectsNonBooleanCanBeImpersonated(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['canBeImpersonated' => 'yes'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchAcceptsValidTimezone(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['timezone' => 'America/New_York'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('America/New_York', $body['timezone']);
    }

    public function testPatchRejectsInvalidTimezone(): void
    {
        $alice = $this->createUser('alice@example.com');

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['timezone' => 'Not/AZone'],
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

    public function testPatchAcceptsNotificationMatrix(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => [
                'notificationMatrix' => [
                    'mentions' => ['inApp' => true, 'email' => false],
                ],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testPatchRejectsMalformedMatrix(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['notificationMatrix' => ['mentions' => ['inApp' => 'yes']]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchRejectsUnknownMatrixRow(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['notificationMatrix' => ['bogus' => ['inApp' => true, 'email' => true]]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchAcceptsQuietHoursAndDigest(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => [
                'quietHours' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00'],
                'emailDigest' => ['mode' => 'daily', 'hour' => 8],
            ],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
    }

    public function testPatchRejectsBadQuietHoursTime(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['quietHours' => ['enabled' => true, 'start' => '25:99', 'end' => '07:00']],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchRejectsBadDigestHour(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['emailDigest' => ['mode' => 'daily', 'hour' => 47]],
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function simpleLandingPageProvider(): iterable
    {
        yield 'tasks' => ['tasks'];
        yield 'notifications' => ['notifications'];
        yield 'spaces' => ['spaces'];
    }

    /**
     * @dataProvider simpleLandingPageProvider
     */
    public function testPatchAcceptsLandingPage(string $page): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['landing' => ['page' => $page, 'spaceId' => null]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $landing = $this->arrayField($response->toArray(), 'landing');
        $this->assertSame($page, $landing['page']);
        $this->assertNull($landing['spaceId']);
    }

    public function testPatchRejectsUnknownLandingPage(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['landing' => ['page' => 'reports', 'spaceId' => null]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchAcceptsLandingSpaceForMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpaceFor($alice);
        $spaceId = $space->getId();
        self::assertNotNull($spaceId);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['landing' => ['page' => 'space', 'spaceId' => (string) $spaceId]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $landing = $this->arrayField($response->toArray(), 'landing');
        $this->assertSame('space', $landing['page']);
        $this->assertSame((string) $spaceId, $landing['spaceId']);
    }

    public function testPatchAcceptsLandingSpaceWithNullSpaceId(): void
    {
        // null spaceId means the personal "Private" space — no lookup.
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['landing' => ['page' => 'space', 'spaceId' => null]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testPatchRejectsLandingSpaceForNonMember(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        // A space Bob owns but Alice has no membership in.
        $space = $this->createSpaceFor($bob);
        $spaceId = $space->getId();
        self::assertNotNull($spaceId);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['landing' => ['page' => 'space', 'spaceId' => (string) $spaceId]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchRejectsLandingSpaceForUnknownSpace(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['landing' => ['page' => 'space', 'spaceId' => (string) Uuid::v4()]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        // Unknown space is rejected the same as a forbidden one (404-shape).
        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchRejectsLandingSpaceForMalformedSpaceId(): void
    {
        $alice = $this->createUser('alice@example.com');
        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['landing' => ['page' => 'space', 'spaceId' => 'not-a-uuid']],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testPatchNormalizesSpaceIdAwayForNonSpacePage(): void
    {
        // A spaceId sent alongside a non-'space' page is dropped on store,
        // so a stale id can't survive a switch to e.g. "tasks".
        $alice = $this->createUser('alice@example.com');
        $space = $this->createSpaceFor($alice);
        $spaceId = $space->getId();
        self::assertNotNull($spaceId);

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('PATCH', '/me/preferences', [
            'json' => ['landing' => ['page' => 'tasks', 'spaceId' => (string) $spaceId]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $landing = $this->arrayField($response->toArray(), 'landing');
        $this->assertSame('tasks', $landing['page']);
        $this->assertNull($landing['spaceId']);
    }

    public function testApiMeReturnsPreferencesInline(): void
    {
        // The PWA reads preferences off the existing /api/me payload so
        // notification + timezone settings apply on first paint without a
        // second round-trip.
        $alice = $this->createUser('alice@example.com');
        $alice->setPreferences(array_merge(User::DEFAULT_PREFERENCES, ['timezone' => 'America/New_York']));
        $this->entityManager->flush();

        $client = static::createClient();
        $client->loginUser($alice);
        $client->request('GET', '/api/me');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('America/New_York', $this->arrayField($body, 'preferences')['timezone']);
    }

    /**
     * @param string[] $roles
     */
    private function createUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

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

    /**
     * A shared space owned by `$owner`, with `$owner` as a direct admin
     * member — enough for the landing-preference membership check.
     */
    private function createSpaceFor(User $owner): Space
    {
        $space = new Space();
        $space->setName('Workspace');
        $space->setCreatedBy($owner);

        $membership = (new SpaceMembership())
            ->setUser($owner)
            ->setRole(Space::ROLE_ADMIN);
        $space->addUserMembership($membership);

        $this->entityManager->persist($space);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        return $space;
    }
}
