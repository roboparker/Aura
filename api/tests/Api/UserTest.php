<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use App\Service\AvatarColorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        // Clean user table before each test
        $this->entityManager->createQuery('DELETE FROM App\Entity\MediaObject')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRegisterUser(): void
    {
        $client = static::createClient();
        $client->request('POST', '/users', [
            'json' => [
                'email' => 'newuser@example.com',
                'plainPassword' => 'Password123!@#',
                'givenName' => 'New',
                'familyName' => 'User',
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'User',
            'email' => 'newuser@example.com',
            'givenName' => 'New',
            'familyName' => 'User',
        ]);

        // Password should not be in response; color should be set to a palette entry
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertArrayNotHasKey('password', $body);
        $this->assertArrayNotHasKey('plainPassword', $body);
        $this->assertContains($body['personalizedColor'], AvatarColorService::PALETTE);
        $this->assertNull($body['avatarUrls'] ?? null);
    }

    public function testRegisterDuplicateEmail(): void
    {
        $this->createTestUser('existing@example.com', 'Password123!@#');

        static::createClient()->request('POST', '/users', [
            'json' => [
                'email' => 'existing@example.com',
                'plainPassword' => 'Password123!@#',
                'givenName' => 'Ex',
                'familyName' => 'Ist',
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegisterInvalidEmail(): void
    {
        static::createClient()->request('POST', '/users', [
            'json' => [
                'email' => 'not-an-email',
                'plainPassword' => 'Password123!@#',
                'givenName' => 'Foo',
                'familyName' => 'Bar',
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegisterBlankPassword(): void
    {
        static::createClient()->request('POST', '/users', [
            'json' => [
                'email' => 'test@example.com',
                'plainPassword' => '',
                'givenName' => 'Foo',
                'familyName' => 'Bar',
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegisterShortPassword(): void
    {
        static::createClient()->request('POST', '/users', [
            'json' => [
                'email' => 'test@example.com',
                'plainPassword' => 'ab',
                'givenName' => 'Foo',
                'familyName' => 'Bar',
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    // The signup-time strength gate is exercised in
    // PasswordPolicyValidatorTest — the test env runs at VERY_WEAK
    // (so "password" registers cleanly here) and the validator's
    // strict-floor behaviour is covered as a unit test independent of
    // the env-driven default.

    public function testRegisterBlankGivenName(): void
    {
        static::createClient()->request('POST', '/users', [
            'json' => [
                'email' => 'test@example.com',
                'plainPassword' => 'Password123!@#',
                'givenName' => '',
                'familyName' => 'User',
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegisterBlankFamilyName(): void
    {
        static::createClient()->request('POST', '/users', [
            'json' => [
                'email' => 'test@example.com',
                'plainPassword' => 'Password123!@#',
                'givenName' => 'User',
                'familyName' => '',
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testLoginSuccess(): void
    {
        $this->createTestUser('login@example.com', 'Password123!@#');

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => [
                'email' => 'login@example.com',
                'password' => 'Password123!@#',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'email' => 'login@example.com',
            'givenName' => 'Test',
            'familyName' => 'User',
        ]);
    }

    public function testLoginWrongPassword(): void
    {
        $this->createTestUser('login@example.com', 'Password123!@#');

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => [
                'email' => 'login@example.com',
                'password' => 'wrongpassword',
            ],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetMeAuthenticated(): void
    {
        $user = $this->createTestUser('me@example.com', 'Password123!@#');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertSame('me@example.com', $body['email']);
        $this->assertSame('Test', $body['givenName']);
        $this->assertSame('User', $body['familyName']);
        $this->assertNotEmpty($body['personalizedColor']);
        $this->assertNull($body['avatarUrls']);
    }

    public function testGetMeUnauthenticated(): void
    {
        static::createClient()->request('GET', '/api/me');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testPatchSelfUpdatesNickname(): void
    {
        $user = $this->createTestUser('patch@example.com', 'Password123!@#');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('PATCH', '/users/' . $user->getId(), [
            'json' => ['nickname' => 'Patchy'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains(['nickname' => 'Patchy']);
    }

    public function testPatchOtherUserForbidden(): void
    {
        $user = $this->createTestUser('self@example.com', 'Password123!@#');
        $other = $this->createTestUser('other@example.com', 'Password123!@#');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('PATCH', '/users/' . $other->getId(), [
            'json' => ['nickname' => 'hacked'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $roles
     */
    private function createTestUser(string $email, string $plainPassword, array $roles = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
