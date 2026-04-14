<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        // Clean user table before each test
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testRegisterUser(): void
    {
        $client = static::createClient();
        $client->request('POST', '/users', [
            'json' => [
                'email' => 'newuser@example.com',
                'plainPassword' => 'password123',
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            '@type' => 'User',
            'email' => 'newuser@example.com',
        ]);

        // Password should not be in response
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('password', $response);
        $this->assertArrayNotHasKey('plainPassword', $response);
    }

    public function testRegisterDuplicateEmail(): void
    {
        $this->createTestUser('existing@example.com', 'password123');

        static::createClient()->request('POST', '/users', [
            'json' => [
                'email' => 'existing@example.com',
                'plainPassword' => 'password123',
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
                'plainPassword' => 'password123',
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
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testLoginSuccess(): void
    {
        $this->createTestUser('login@example.com', 'password123');

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => [
                'email' => 'login@example.com',
                'password' => 'password123',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'email' => 'login@example.com',
        ]);
    }

    public function testLoginWrongPassword(): void
    {
        $this->createTestUser('login@example.com', 'password123');

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
        $user = $this->createTestUser('me@example.com', 'password123');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        $this->assertJsonContains([
            'email' => 'me@example.com',
        ]);
    }

    public function testGetMeUnauthenticated(): void
    {
        static::createClient()->request('GET', '/api/me');
        $this->assertResponseStatusCodeSame(401);
    }

    private function createTestUser(string $email, string $plainPassword, array $roles = ['ROLE_USER']): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
