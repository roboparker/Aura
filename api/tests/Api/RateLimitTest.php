<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Abuse throttles on the unauthenticated endpoints: login (firewall
 * login_throttling), signup (UserPasswordHasherProcessor), and
 * forgot-password (PasswordController).
 *
 * Two tricks keep these tests isolated from the rest of the suite and
 * from each other across runs (the limiter windows live in the
 * filesystem cache pool, which persists between PHPUnit runs):
 *
 *  - The limits are env placeholders resolved at runtime, so each test
 *    overrides $_ENV to a small budget before booting its kernel;
 *    .env.test's effectively-unlimited defaults are restored in
 *    tearDown for everything else.
 *  - Every test keys its buckets with throwaway client IPs (TEST-NET
 *    ranges via X-Forwarded-For — 127.0.0.1 is a trusted proxy, so
 *    getClientIp() honors the header) and unique emails, never touching
 *    the 127.0.0.1 buckets the rest of the suite runs in.
 */
class RateLimitTest extends ApiTestCase
{
    /** @var array<string, string|null> */
    private array $previousEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if (null === $value) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
        $this->previousEnv = [];

        parent::tearDown();
    }

    // --- Login (json_login + login_throttling) ---

    public function testLoginThrottledPerIpAndEmail(): void
    {
        $this->setRateLimitEnv('RATE_LIMIT_LOGIN_ATTEMPTS', '2');

        $client = static::createClient();
        $ip = self::uniqueIp();
        $email = self::uniqueEmail('login-throttle');

        for ($i = 0; $i < 2; ++$i) {
            $this->postLogin($client, $email, 'wrong-password', $ip);
            $this->assertResponseStatusCodeSame(401);
        }

        $this->postLogin($client, $email, 'wrong-password', $ip);
        $this->assertResponseStatusCodeSame(429);
        $this->assertResponseHasHeader('Retry-After');
        $body = $this->responseBody($client);
        $this->assertArrayHasKey('retryAfter', $body);
        $error = $body['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('Too many failed login attempts', $error);

        // Same email from another IP is a different bucket — back to the
        // ordinary 401, no cross-network lockout.
        $this->postLogin($client, $email, 'wrong-password', self::uniqueIp());
        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginThrottledPerIpAcrossEmails(): void
    {
        $this->setRateLimitEnv('RATE_LIMIT_LOGIN_ATTEMPTS_PER_IP', '2');

        $client = static::createClient();
        $ip = self::uniqueIp();

        // Distinct emails dodge the per-(IP, email) limiter; the wider
        // per-IP net still catches the third attempt.
        $this->postLogin($client, self::uniqueEmail('spray-a'), 'wrong-password', $ip);
        $this->assertResponseStatusCodeSame(401);
        $this->postLogin($client, self::uniqueEmail('spray-b'), 'wrong-password', $ip);
        $this->assertResponseStatusCodeSame(401);

        $this->postLogin($client, self::uniqueEmail('spray-c'), 'wrong-password', $ip);
        $this->assertResponseStatusCodeSame(429);
    }

    public function testSuccessfulLoginDoesNotConsumeBudget(): void
    {
        $this->setRateLimitEnv('RATE_LIMIT_LOGIN_ATTEMPTS', '2');

        $client = static::createClient();
        $ip = self::uniqueIp();
        $email = self::uniqueEmail('login-success');
        $this->createTestUser($email, 'Password123!@#');

        // Only *failed* attempts count: the throttling listener peeks at
        // CheckPassport and consumes on LoginFailureEvent, so a signed-in
        // user's successes never eat into the budget. With a budget of 2,
        // a failure + a success still leaves one failure before the 429.
        $this->postLogin($client, $email, 'wrong-password', $ip);
        $this->assertResponseStatusCodeSame(401);
        $this->postLogin($client, $email, 'Password123!@#', $ip);
        $this->assertResponseStatusCodeSame(200);

        $this->postLogin($client, $email, 'wrong-password', $ip);
        $this->assertResponseStatusCodeSame(401);
        $this->postLogin($client, $email, 'wrong-password', $ip);
        $this->assertResponseStatusCodeSame(429);
    }

    // --- Signup (POST /users) ---

    public function testSignupThrottledPerIp(): void
    {
        $this->setRateLimitEnv('RATE_LIMIT_SIGNUPS_PER_IP', '2');

        $client = static::createClient();
        $ip = self::uniqueIp();

        $this->postSignup($client, self::uniqueEmail('signup-a'), $ip);
        $this->assertResponseStatusCodeSame(201);
        $this->postSignup($client, self::uniqueEmail('signup-b'), $ip);
        $this->assertResponseStatusCodeSame(201);

        $blockedEmail = self::uniqueEmail('signup-c');
        $this->postSignup($client, $blockedEmail, $ip);
        $this->assertResponseStatusCodeSame(429);
        $this->assertResponseHasHeader('Retry-After');

        // Throttled before the persist — no half-created account.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assertNull($em->getRepository(User::class)->findOneBy(['email' => $blockedEmail]));

        // A different network keeps signing up fine.
        $this->postSignup($client, self::uniqueEmail('signup-d'), self::uniqueIp());
        $this->assertResponseStatusCodeSame(201);
    }

    // --- Forgot password ---

    public function testForgotPasswordThrottledPerIp(): void
    {
        $this->setRateLimitEnv('RATE_LIMIT_FORGOT_PASSWORD_PER_IP', '2');

        $client = static::createClient();
        $ip = self::uniqueIp();

        // Unknown emails throttle exactly like registered ones — the
        // limiter is consumed before the user lookup, so a 429 carries no
        // enumeration signal.
        $this->postForgotPassword($client, self::uniqueEmail('forgot-ip-a'), $ip);
        $this->assertResponseStatusCodeSame(200);
        $this->postForgotPassword($client, self::uniqueEmail('forgot-ip-b'), $ip);
        $this->assertResponseStatusCodeSame(200);

        $this->postForgotPassword($client, self::uniqueEmail('forgot-ip-c'), $ip);
        $this->assertResponseStatusCodeSame(429);
        $this->assertResponseHasHeader('Retry-After');
        $body = $this->responseBody($client);
        $this->assertArrayHasKey('retryAfter', $body);

        $this->postForgotPassword($client, self::uniqueEmail('forgot-ip-d'), self::uniqueIp());
        $this->assertResponseStatusCodeSame(200);
    }

    public function testForgotPasswordThrottledPerEmail(): void
    {
        $this->setRateLimitEnv('RATE_LIMIT_FORGOT_PASSWORD_PER_EMAIL', '2');

        $client = static::createClient();
        $email = self::uniqueEmail('forgot-email');
        $user = $this->createTestUser($email, 'Password123!@#');

        // Distinct IPs each time: only the per-email bucket can trip.
        $this->postForgotPassword($client, $email, self::uniqueIp());
        $this->assertResponseStatusCodeSame(200);
        // Casing doesn't dodge the limit — the key is lowercased.
        $this->postForgotPassword($client, strtoupper($email), self::uniqueIp());
        $this->assertResponseStatusCodeSame(200);

        $this->postForgotPassword($client, $email, self::uniqueIp());
        $this->assertResponseStatusCodeSame(429);
        $this->assertResponseHasHeader('Retry-After');

        // The throttled request was rejected before minting a token: only
        // the first (lowercase, matching) request created one.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tokens = $em->getRepository(PasswordResetToken::class)->findBy(['user' => $user]);
        $this->assertCount(1, $tokens);

        // Identical pattern for an address with no account — parity again.
        $unknown = self::uniqueEmail('forgot-unknown');
        $this->postForgotPassword($client, $unknown, self::uniqueIp());
        $this->assertResponseStatusCodeSame(200);
        $this->postForgotPassword($client, $unknown, self::uniqueIp());
        $this->assertResponseStatusCodeSame(200);
        $this->postForgotPassword($client, $unknown, self::uniqueIp());
        $this->assertResponseStatusCodeSame(429);
    }

    // --- Helpers ---

    /**
     * Overrides a rate-limit env var for this test only (restored in
     * tearDown). Must run before createClient(): the limits are env
     * placeholders, resolved when the freshly booted kernel first
     * instantiates the limiter.
     */
    private function setRateLimitEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->previousEnv)) {
            $previous = $_ENV[$name] ?? null;
            $this->previousEnv[$name] = is_string($previous) ? $previous : null;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * A throwaway public client IP (TEST-NET-2 style 198.51.0.0/16), sent
     * as X-Forwarded-For. Random per call so limiter buckets never collide
     * across tests or across runs — the windows outlive the process in the
     * filesystem cache pool.
     */
    private static function uniqueIp(): string
    {
        return sprintf('198.51.%d.%d', random_int(0, 255), random_int(1, 254));
    }

    private static function uniqueEmail(string $prefix): string
    {
        return sprintf('%s-%s@example.com', $prefix, bin2hex(random_bytes(6)));
    }

    private function postLogin(Client $client, string $email, string $password, string $ip): void
    {
        $client->request('POST', '/auth/login', [
            'json' => ['email' => $email, 'password' => $password],
            'headers' => ['X-Forwarded-For' => $ip],
        ]);
    }

    private function postSignup(Client $client, string $email, string $ip): void
    {
        $client->request('POST', '/users', [
            'json' => [
                'email' => $email,
                'plainPassword' => 'Password123!@#',
                'givenName' => 'Rate',
                'familyName' => 'Limit',
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'X-Forwarded-For' => $ip,
            ],
        ]);
    }

    private function postForgotPassword(Client $client, string $email, string $ip): void
    {
        $client->request('POST', '/auth/forgot-password', [
            'json' => ['email' => $email],
            'headers' => ['X-Forwarded-For' => $ip],
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function responseBody(Client $client): array
    {
        $response = $client->getResponse();
        self::assertNotNull($response);

        return $response->toArray(false);
    }

    private function createTestUser(string $email, string $plainPassword): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Rate');
        $user->setFamilyName('Limit');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
