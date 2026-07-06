<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\EmailVerificationToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Exercises the open-signup email-confirmation gate. The suite defaults
 * EMAIL_VERIFICATION_REQUIRED=0 (so the rest of the tests register
 * immediately-usable accounts); this class flips it to 1 via $_ENV before
 * each client boots — the same runtime-resolved-env trick RateLimitTest uses.
 */
class EmailVerificationTest extends ApiTestCase
{
    private EntityManagerInterface $entityManager;
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        // Require verification for every case in this class. Set before any
        // createClient() so the freshly booted kernel resolves the param to
        // true (Symfony env placeholders resolve at runtime from $_ENV).
        $previous = $_ENV['EMAIL_VERIFICATION_REQUIRED'] ?? null;
        $this->previousEnv = is_string($previous) ? $previous : null;
        $_ENV['EMAIL_VERIFICATION_REQUIRED'] = '1';
        $_SERVER['EMAIL_VERIFICATION_REQUIRED'] = '1';

        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->entityManager = $em;

        $this->entityManager->createQuery('DELETE FROM App\Entity\EmailVerificationToken')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    protected function tearDown(): void
    {
        if (null === $this->previousEnv) {
            unset($_ENV['EMAIL_VERIFICATION_REQUIRED'], $_SERVER['EMAIL_VERIFICATION_REQUIRED']);
        } else {
            $_ENV['EMAIL_VERIFICATION_REQUIRED'] = $this->previousEnv;
            $_SERVER['EMAIL_VERIFICATION_REQUIRED'] = $this->previousEnv;
        }

        parent::tearDown();
    }

    public function testSignupLandsUnverifiedAndSendsConfirmationEmail(): void
    {
        $client = static::createClient();
        $this->register($client, 'newbie@example.com');
        $this->assertResponseStatusCodeSame(201);

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $this->assertEmailAddressContains($email, 'To', 'newbie@example.com');
        $this->assertEmailHeaderSame($email, 'Subject', 'Confirm your Madori email');

        $user = $this->reloadUser('newbie@example.com');
        $this->assertFalse($user->isEmailVerified());
        $this->assertSame(['ROLE_UNVERIFIED'], $user->getRoles());
    }

    public function testUnverifiedUserCanReadMeButIsBlockedElsewhere(): void
    {
        $user = $this->createUnverifiedUser('boxed@example.com');

        $client = static::createClient();
        $client->loginUser($user);

        // /api/me works so the PWA can route to the verify gate…
        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray();
        $this->assertFalse($body['emailVerified'] ?? true);

        // …but a ROLE_USER-gated endpoint is forbidden (no ROLE_USER).
        $client->request('GET', '/api-tokens');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testVerifyLinkVerifiesAndUnlocksTheAccount(): void
    {
        $client = static::createClient();
        $this->register($client, 'confirm@example.com');
        $token = $this->extractTokenFromLastEmail();

        $client->request('POST', '/verify-email', [
            'json' => ['token' => $token],
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertResponseIsSuccessful();

        $user = $this->reloadUser('confirm@example.com');
        $this->assertTrue($user->isEmailVerified());
        $this->assertContains('ROLE_USER', $user->getRoles());

        // The now-verified account can reach ROLE_USER endpoints.
        $client->loginUser($user);
        $client->request('GET', '/api-tokens');
        $this->assertResponseIsSuccessful();
    }

    public function testVerifyIsIdempotentOnSecondClick(): void
    {
        $client = static::createClient();
        $this->register($client, 'twice@example.com');
        $token = $this->extractTokenFromLastEmail();

        for ($i = 0; $i < 2; ++$i) {
            $client->request('POST', '/verify-email', [
                'json' => ['token' => $token],
                'headers' => ['Content-Type' => 'application/json'],
            ]);
            // A used token whose owner is verified is a harmless double-click.
            $this->assertResponseIsSuccessful();
        }
    }

    public function testVerifyInvalidToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/verify-email', [
            'json' => ['token' => 'nope'],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame('token_invalid', $response->toArray(false)['code'] ?? null);
    }

    public function testVerifyExpiredToken(): void
    {
        $user = $this->createUnverifiedUser('expired@example.com');
        $plainToken = $this->createTokenFor($user, new \DateTimeImmutable('-1 hour'));

        $client = static::createClient();
        $client->request('POST', '/verify-email', [
            'json' => ['token' => $plainToken],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $this->assertSame('token_expired', $response->toArray(false)['code'] ?? null);
    }

    public function testResendSendsAFreshLink(): void
    {
        $user = $this->createUnverifiedUser('resend@example.com');

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/me/verification/resend', [
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(1);

        $tokens = $this->entityManager->getRepository(EmailVerificationToken::class)->findAll();
        $this->assertCount(1, $tokens);
    }

    public function testResendNoopWhenAlreadyVerified(): void
    {
        // Default-constructed user is verified (the inverse-gating default).
        $user = $this->createTestUser('done@example.com');
        $this->assertTrue($user->isEmailVerified());

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('POST', '/me/verification/resend', [
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(0);
    }

    // --- Helpers ---

    private function register(Client $client, string $email): void
    {
        $client->request('POST', '/users', [
            'json' => [
                'email' => $email,
                'plainPassword' => 'Password123!@#',
                'givenName' => 'New',
                'familyName' => 'User',
            ],
            'headers' => ['Content-Type' => 'application/ld+json'],
        ]);
    }

    private function extractTokenFromLastEmail(): string
    {
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $textBody = $email->getTextBody();
        self::assertIsString($textBody);
        self::assertSame(
            1,
            preg_match('#/verify-email\?token=([a-f0-9]+)#', $textBody, $matches),
            'The confirmation email should contain a verify-email link.',
        );

        return $matches[1];
    }

    private function createTestUser(string $email): User
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

    private function createUnverifiedUser(string $email): User
    {
        $user = $this->createTestUser($email);
        $user->setEmailVerified(false);
        $this->entityManager->flush();

        return $user;
    }

    private function createTokenFor(User $user, ?\DateTimeImmutable $expiresAt = null): string
    {
        $plainToken = bin2hex(random_bytes(32));
        $expiresAt ??= new \DateTimeImmutable('+24 hours');

        $token = new EmailVerificationToken($user, hash('sha256', $plainToken), $expiresAt);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $plainToken;
    }

    private function reloadUser(string $email): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user, sprintf('User %s should exist', $email));

        return $user;
    }
}
