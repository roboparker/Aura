<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use App\Service\TwoFactorSetupService;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TwoFactorTest extends ApiTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    // --- Setup + verify ---

    public function testSetupReturnsSecretAndProvisioningUri(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createTestUser('s@example.com'));

        $client->request('POST', '/me/2fa/setup');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray(false);
        $this->assertNotEmpty($body['secret']);
        $provisioningUri = $body['provisioningUri'];
        $this->assertIsString($provisioningUri);
        $this->assertStringStartsWith('otpauth://totp/', $provisioningUri);
    }

    public function testSetupRejectedWhenAlreadyEnabled(): void
    {
        $user = $this->createTestUser('already@example.com');
        $this->enableTwoFactor($user);

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/me/2fa/setup');

        $this->assertResponseStatusCodeSame(409);
    }

    public function testVerifyEnablesAndReturnsRecoveryCodes(): void
    {
        $user = $this->createTestUser('v@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/me/2fa/setup');
        $response = $client->getResponse();
        self::assertNotNull($response);
        $secret = $response->toArray(false)['secret'] ?? null;

        $reloaded = $this->reloadUser('v@example.com');
        $totpSecret = $reloaded->getDecryptedTotpSecret();
        self::assertNotNull($totpSecret);
        self::assertNotSame('', $totpSecret);
        $code = TOTP::createFromSecret($totpSecret)->now();

        $client->request('POST', '/me/2fa/verify', ['json' => ['code' => $code]]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray(false);
        $this->assertTrue($body['enabled']);
        $recoveryCodes = $body['recoveryCodes'];
        $this->assertIsArray($recoveryCodes);
        $this->assertCount(TwoFactorSetupService::RECOVERY_CODE_COUNT, $recoveryCodes);

        $refreshed = $this->reloadUser('v@example.com');
        $this->assertTrue($refreshed->isTotpEnabled());
        $this->assertSame(TwoFactorSetupService::RECOVERY_CODE_COUNT, $refreshed->getRecoveryCodeCount());
    }

    public function testVerifyRejectsInvalidCode(): void
    {
        $user = $this->createTestUser('v@example.com');
        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/me/2fa/setup');
        $client->request('POST', '/me/2fa/verify', ['json' => ['code' => '000000']]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertFalse($this->reloadUser('v@example.com')->isTotpEnabled());
    }

    // --- Login flow ---

    public function testLoginWithoutTwoFactorReturnsUser(): void
    {
        $this->createTestUser('plain@example.com', 'password123');

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'plain@example.com', 'password' => 'password123'],
        ]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray(false);
        $this->assertSame('plain@example.com', $body['email']);
        $twoFactor = $body['twoFactor'];
        $this->assertIsArray($twoFactor);
        $this->assertFalse($twoFactor['enabled']);
    }

    public function testLoginWithTwoFactorPromptsForCode(): void
    {
        $user = $this->createTestUser('tfa@example.com', 'password123');
        $this->enableTwoFactor($user);

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'tfa@example.com', 'password' => 'password123'],
        ]);

        $this->assertResponseStatusCodeSame(401);
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray(false);
        $this->assertTrue($body['requiresTwoFactor']);
    }

    public function testLoginCompletesAfterValidTotpCode(): void
    {
        $user = $this->createTestUser('tfa@example.com', 'password123');
        $this->enableTwoFactor($user);

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'tfa@example.com', 'password' => 'password123'],
        ]);
        $this->assertResponseStatusCodeSame(401);

        $totpSecret = $this->reloadUser('tfa@example.com')->getDecryptedTotpSecret();
        self::assertNotNull($totpSecret);
        self::assertNotSame('', $totpSecret);
        $code = TOTP::createFromSecret($totpSecret)->now();

        $client->request('POST', '/auth/2fa-check', ['json' => ['code' => $code]]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray(false);
        $this->assertSame('tfa@example.com', $body['email']);
    }

    public function testLoginCompletesWithRecoveryCode(): void
    {
        $user = $this->createTestUser('tfa@example.com', 'password123');
        $codes = $this->enableTwoFactor($user);

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'tfa@example.com', 'password' => 'password123'],
        ]);
        $client->request('POST', '/auth/2fa-check', ['json' => ['code' => $codes[0]]]);

        $this->assertResponseIsSuccessful();
        // Single-use: the same code must not work a second time on a fresh login
        $client2 = static::createClient();
        $client2->request('POST', '/auth/login', [
            'json' => ['email' => 'tfa@example.com', 'password' => 'password123'],
        ]);
        $client2->request('POST', '/auth/2fa-check', ['json' => ['code' => $codes[0]]]);
        $this->assertResponseStatusCodeSame(401);

        $refreshed = $this->reloadUser('tfa@example.com');
        $this->assertSame(TwoFactorSetupService::RECOVERY_CODE_COUNT - 1, $refreshed->getRecoveryCodeCount());
    }

    // --- Disable + regenerate ---

    public function testDisableRequiresPassword(): void
    {
        $user = $this->createTestUser('d@example.com', 'password123');
        $this->enableTwoFactor($user);

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('DELETE', '/me/2fa', ['json' => ['currentPassword' => 'wrong']]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertTrue($this->reloadUser('d@example.com')->isTotpEnabled());

        $client->request('DELETE', '/me/2fa', ['json' => ['currentPassword' => 'password123']]);
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->reloadUser('d@example.com')->isTotpEnabled());
    }

    public function testRegenerateRecoveryCodes(): void
    {
        $user = $this->createTestUser('r@example.com', 'password123');
        $original = $this->enableTwoFactor($user);

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/me/2fa/recovery-codes', [
            'json' => ['currentPassword' => 'password123'],
        ]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray(false);
        $recoveryCodes = $body['recoveryCodes'];
        $this->assertIsArray($recoveryCodes);
        /** @var list<string> $recoveryCodes */
        $this->assertCount(TwoFactorSetupService::RECOVERY_CODE_COUNT, $recoveryCodes);
        $this->assertEmpty(
            array_intersect($original, $recoveryCodes),
            'Old recovery codes should not appear in the regenerated set.'
        );
    }

    // --- Helpers ---

    private function createTestUser(string $email, string $password = 'password123'): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Test');
        $user->setFamilyName('User');
        $user->setPersonalizedColor('#0369a1');
        $user->setPassword($hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return string[] plaintext recovery codes */
    private function enableTwoFactor(User $user): array
    {
        $setup = static::getContainer()->get(TwoFactorSetupService::class);
        $setup->startSetup($user);
        $user->setTotpEnabled(true);
        $codes = $setup->regenerateRecoveryCodes($user);
        $this->em->flush();
        return $codes;
    }

    private function reloadUser(string $email): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->assertNotNull($user);
        return $user;
    }
}
