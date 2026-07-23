<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Admin account provisioning (#admin-user-create): POST /admin/users creates a
 * sign-in-ready account (both signup gates cleared), returns the generated
 * password once, and is platform-admin only.
 */
class AdminUserCreateTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\SpaceMembership')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Space')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAdminCreatesUsableAccountWithOneShotPassword(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('POST', '/admin/users', [
            'json' => [
                'email' => 'new.person@example.com',
                'givenName' => 'New',
                'familyName' => 'Person',
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);

        $this->assertSame('new.person@example.com', $body['email'] ?? null);
        $this->assertFalse($body['waitlisted'] ?? true);

        $password = $body['password'] ?? null;
        $this->assertIsString($password);
        $this->assertNotSame('', $password);

        // The account is genuinely usable: both the email-confirmation and
        // waitlist gates are cleared, so it holds ROLE_USER right away.
        $created = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'new.person@example.com']);
        assert($created instanceof User);
        $this->assertContains('ROLE_USER', $created->getRoles());
        $this->assertTrue($created->isEmailVerified());
        $this->assertFalse($created->isWaitlisted());

        // …and it got its personal space.
        $spaces = $this->entityManager->getRepository(Space::class)->findBy(['createdBy' => $created]);
        $this->assertCount(1, $spaces);
        $this->assertTrue($spaces[0]->getIsPersonal());
    }

    public function testCreatesSharedSpaceWhenRequested(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('POST', '/admin/users', [
            'json' => [
                'email' => 'studio@example.com',
                'givenName' => 'Studio',
                'familyName' => 'Owner',
                'spaceName' => 'Acme Studio',
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);

        $space = $body['space'] ?? null;
        $this->assertIsArray($space);
        $this->assertSame('Acme Studio', $space['name'] ?? null);

        $created = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'studio@example.com']);
        assert($created instanceof User);
        // Personal + the requested shared space.
        $this->assertCount(2, $this->entityManager->getRepository(Space::class)->findBy(['createdBy' => $created]));
    }

    public function testSkipWaitlistFalseLeavesAccountWaiting(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);

        $client = static::createClient();
        $client->loginUser($admin);

        $body = $client->request('POST', '/admin/users', [
            'json' => [
                'email' => 'waiting@example.com',
                'givenName' => 'Wait',
                'familyName' => 'Ing',
                'skipWaitlist' => false,
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ])->toArray();
        $this->assertResponseStatusCodeSame(201);
        $this->assertTrue($body['waitlisted'] ?? false);
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);
        $this->makeUser('taken@example.com');

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/admin/users', [
            'json' => [
                'email' => 'taken@example.com',
                'givenName' => 'Dupe',
                'familyName' => 'User',
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $admin = $this->makeUser('admin@example.com', ['ROLE_ADMIN']);

        $client = static::createClient();
        $client->loginUser($admin);

        $client->request('POST', '/admin/users', [
            'json' => ['email' => 'not-an-email', 'givenName' => 'A', 'familyName' => 'B'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testNonAdminCannotCreateUsers(): void
    {
        $plain = $this->makeUser('plain@example.com');

        $client = static::createClient();
        $client->loginUser($plain);

        $client->request('POST', '/admin/users', [
            'json' => ['email' => 'nope@example.com', 'givenName' => 'No', 'familyName' => 'Pe'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $roles
     */
    private function makeUser(string $email, array $roles = []): User
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
