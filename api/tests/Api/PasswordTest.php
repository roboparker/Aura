<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Test\Constraint as MailerAssertions;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PasswordTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        // Clean tables before each test
        $this->entityManager->createQuery('DELETE FROM App\Entity\PasswordResetToken')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    // --- Change password ---

    public function testChangePasswordSuccess(): void
    {
        $user = $this->createTestUser('change@example.com', 'oldpassword');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/auth/change-password', [
            'json' => [
                'currentPassword' => 'oldpassword',
                'newPassword' => 'newpassword123',
            ],
        ]);

        $this->assertResponseIsSuccessful();

        // Verify the password actually changed (re-fetch from a fresh EM)
        $refreshed = $this->reloadUser('change@example.com');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($refreshed, 'newpassword123'));
        $this->assertFalse($hasher->isPasswordValid($refreshed, 'oldpassword'));
    }

    public function testChangePasswordWrongCurrent(): void
    {
        $user = $this->createTestUser('change@example.com', 'oldpassword');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/auth/change-password', [
            'json' => [
                'currentPassword' => 'wrongpassword',
                'newPassword' => 'newpassword123',
            ],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testChangePasswordTooShort(): void
    {
        $user = $this->createTestUser('change@example.com', 'oldpassword');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/auth/change-password', [
            'json' => [
                'currentPassword' => 'oldpassword',
                'newPassword' => 'abc',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testChangePasswordSameAsCurrent(): void
    {
        $user = $this->createTestUser('change@example.com', 'oldpassword');

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/auth/change-password', [
            'json' => [
                'currentPassword' => 'oldpassword',
                'newPassword' => 'oldpassword',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testChangePasswordUnauthenticated(): void
    {
        static::createClient()->request('POST', '/auth/change-password', [
            'json' => [
                'currentPassword' => 'oldpassword',
                'newPassword' => 'newpassword123',
            ],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    // --- Forgot password ---

    public function testForgotPasswordValidEmail(): void
    {
        $this->createTestUser('forgot@example.com', 'password123');

        $client = static::createClient();
        $client->request('POST', '/auth/forgot-password', [
            'json' => ['email' => 'forgot@example.com'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(1);

        // Verify a token was created
        $tokens = $this->entityManager->getRepository(PasswordResetToken::class)->findAll();
        $this->assertCount(1, $tokens);
    }

    public function testForgotPasswordUnknownEmailStillReturns200(): void
    {
        $client = static::createClient();
        $client->request('POST', '/auth/forgot-password', [
            'json' => ['email' => 'nobody@example.com'],
        ]);

        // Prevents email enumeration: always 200, no email sent, no token created
        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(0);

        $tokens = $this->entityManager->getRepository(PasswordResetToken::class)->findAll();
        $this->assertCount(0, $tokens);
    }

    public function testForgotPasswordInvalidatesPriorTokens(): void
    {
        $this->createTestUser('forgot@example.com', 'password123');

        $client = static::createClient();

        // Request twice
        $client->request('POST', '/auth/forgot-password', [
            'json' => ['email' => 'forgot@example.com'],
        ]);
        $client->request('POST', '/auth/forgot-password', [
            'json' => ['email' => 'forgot@example.com'],
        ]);

        $tokens = $this->entityManager->getRepository(PasswordResetToken::class)->findAll();
        $this->assertCount(2, $tokens);

        $unusedTokens = array_filter($tokens, fn (PasswordResetToken $t) => null === $t->getUsedAt());
        $this->assertCount(1, $unusedTokens, 'Only the newest token should remain unused');
    }

    // --- Reset password ---

    public function testResetPasswordSuccess(): void
    {
        $user = $this->createTestUser('reset@example.com', 'oldpassword');
        $plainToken = $this->createResetToken($user);

        $client = static::createClient();
        $client->request('POST', '/auth/reset-password', [
            'json' => [
                'token' => $plainToken,
                'newPassword' => 'brandnewpass',
            ],
        ]);

        $this->assertResponseIsSuccessful();

        $refreshed = $this->reloadUser('reset@example.com');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($refreshed, 'brandnewpass'));
    }

    public function testResetPasswordInvalidToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/auth/reset-password', [
            'json' => [
                'token' => 'nonexistent-token',
                'newPassword' => 'brandnewpass',
            ],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testResetPasswordExpiredToken(): void
    {
        $user = $this->createTestUser('reset@example.com', 'oldpassword');
        $plainToken = $this->createResetToken($user, new \DateTimeImmutable('-1 hour'));

        $client = static::createClient();
        $client->request('POST', '/auth/reset-password', [
            'json' => [
                'token' => $plainToken,
                'newPassword' => 'brandnewpass',
            ],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testResetPasswordUsedToken(): void
    {
        $user = $this->createTestUser('reset@example.com', 'oldpassword');
        $plainToken = $this->createResetToken($user);

        $client = static::createClient();

        // First use succeeds
        $client->request('POST', '/auth/reset-password', [
            'json' => [
                'token' => $plainToken,
                'newPassword' => 'brandnewpass',
            ],
        ]);
        $this->assertResponseIsSuccessful();

        // Second use fails
        $client->request('POST', '/auth/reset-password', [
            'json' => [
                'token' => $plainToken,
                'newPassword' => 'anothernewpass',
            ],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testResetPasswordTooShort(): void
    {
        $user = $this->createTestUser('reset@example.com', 'oldpassword');
        $plainToken = $this->createResetToken($user);

        $client = static::createClient();
        $client->request('POST', '/auth/reset-password', [
            'json' => [
                'token' => $plainToken,
                'newPassword' => 'abc',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    // --- Helpers ---

    private function createTestUser(string $email, string $plainPassword): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function reloadUser(string $email): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user, sprintf('User %s should exist', $email));

        return $user;
    }

    private function createResetToken(User $user, ?\DateTimeImmutable $expiresAt = null): string
    {
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt ??= new \DateTimeImmutable('+1 hour');

        $token = new PasswordResetToken($user, $tokenHash, $expiresAt);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $plainToken;
    }
}
