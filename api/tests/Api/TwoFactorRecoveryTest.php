<?php

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use App\Service\TwoFactorSetupService;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Recovery-flow tests for the backup-code login path. The legacy
 * {@see TwoFactorTest} covers everything assuming the user has a working
 * authenticator; this file pins down the "lost device" branch:
 *   - signing in with a recovery code flags the session
 *   - the flag surfaces in both /auth/2fa-check and /api/me payloads
 *   - the disable + reenroll endpoints accept the password floor while
 *     the flag is set, then clear it on success
 *   - notification emails fire for the three milestones
 *     (used, disabled, reconfigured)
 */
class TwoFactorRecoveryTest extends ApiTestCase
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

    public function testBackupCodeLoginFlagsSessionAndSendsEmail(): void
    {
        $codes = $this->seedTwoFactorUser('recover@example.com', 'Password123!@#');

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'recover@example.com', 'password' => 'Password123!@#'],
        ]);
        $this->assertResponseStatusCodeSame(401);

        $client->request('POST', '/auth/2fa-check', ['json' => ['code' => $codes[0]]]);

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray(false);
        $twoFactor = $body['twoFactor'];
        $this->assertIsArray($twoFactor);
        $this->assertTrue($twoFactor['recoveryPending']);

        // The one-shot notification email lands the moment the code is
        // accepted — even before the user picks recover vs disable, so a
        // stolen-code scenario produces an out-of-band alert.
        $this->assertEmailCount(1);
    }

    public function testApiMeReflectsRecoveryPendingAfterBackupLogin(): void
    {
        $codes = $this->seedTwoFactorUser('me@example.com', 'Password123!@#');

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'me@example.com', 'password' => 'Password123!@#'],
        ]);
        $client->request('POST', '/auth/2fa-check', ['json' => ['code' => $codes[0]]]);
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/api/me');
        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertNotNull($response);
        $body = $response->toArray(false);
        $twoFactor = $body['twoFactor'];
        $this->assertIsArray($twoFactor);
        $this->assertTrue($twoFactor['recoveryPending']);
    }

    public function testDisableAcceptsPasswordDuringRecovery(): void
    {
        $codes = $this->seedTwoFactorUser('disable@example.com', 'Password123!@#');

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'disable@example.com', 'password' => 'Password123!@#'],
        ]);
        $client->request('POST', '/auth/2fa-check', ['json' => ['code' => $codes[0]]]);
        $this->assertResponseIsSuccessful();

        $client->request('DELETE', '/me/2fa', [
            'json' => ['currentPassword' => 'Password123!@#'],
        ]);

        $this->assertResponseIsSuccessful();
        // assertEmailCount sees only the last request — the disable
        // call fired the "2FA disabled" notification.
        $this->assertEmailCount(1);
        $refreshed = $this->reloadUser('disable@example.com');
        $this->assertFalse($refreshed->isTotpEnabled());

        // /api/me now reports recoveryPending=false because the flag was
        // cleared on the way out of disable().
        $client->request('GET', '/api/me');
        $body = $client->getResponse()->toArray(false);
        $twoFactor = $body['twoFactor'];
        $this->assertIsArray($twoFactor);
        $this->assertFalse($twoFactor['recoveryPending']);
    }

    public function testDisableInRecoveryStillRejectsWrongPassword(): void
    {
        $codes = $this->seedTwoFactorUser('wrong@example.com', 'Password123!@#');

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'wrong@example.com', 'password' => 'Password123!@#'],
        ]);
        $client->request('POST', '/auth/2fa-check', ['json' => ['code' => $codes[0]]]);

        // Recovery flag is set, but a stolen-code attacker still needs
        // the password — we don't collapse the second factor down to
        // session-cookie-only.
        $client->request('DELETE', '/me/2fa', [
            'json' => ['currentPassword' => 'WrongPassword!@#'],
        ]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertTrue($this->reloadUser('wrong@example.com')->isTotpEnabled());
    }

    public function testReenrollRotatesSecretAndVerifyClearsFlag(): void
    {
        $codes = $this->seedTwoFactorUser('rotate@example.com', 'Password123!@#');
        $originalSecret = $this->reloadUser('rotate@example.com')->getDecryptedTotpSecret();
        self::assertNotNull($originalSecret);

        $client = static::createClient();
        $client->request('POST', '/auth/login', [
            'json' => ['email' => 'rotate@example.com', 'password' => 'Password123!@#'],
        ]);
        $client->request('POST', '/auth/2fa-check', ['json' => ['code' => $codes[0]]]);
        $this->assertResponseIsSuccessful();

        $client->request('POST', '/me/2fa/reenroll', [
            'json' => ['currentPassword' => 'Password123!@#'],
        ]);
        $this->assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray(false);
        $secret = $body['secret'];
        $this->assertIsString($secret);
        $this->assertNotSame('', $secret);
        $this->assertNotSame($originalSecret, $secret);

        // The reenroll endpoint resets the secret and flips 2FA off
        // until the user confirms the new code.
        $reloaded = $this->reloadUser('rotate@example.com');
        $this->assertFalse($reloaded->isTotpEnabled());

        $code = TOTP::createFromSecret($secret)->now();
        $client->request('POST', '/me/2fa/verify', ['json' => ['code' => $code]]);
        $this->assertResponseIsSuccessful();
        // The "reconfigured" notification fires from the verify call —
        // assert right after it, before the next request resets the
        // per-request mailer logger.
        $this->assertEmailCount(1);

        $refreshed = $this->reloadUser('rotate@example.com');
        $this->assertTrue($refreshed->isTotpEnabled());
        $this->assertSame(
            TwoFactorSetupService::RECOVERY_CODE_COUNT,
            $refreshed->getRecoveryCodeCount(),
            'A fresh recovery-code batch should land alongside the new secret.',
        );

        // Flag clears on the verify call so the PWA stops mounting the
        // interstitial.
        $client->request('GET', '/api/me');
        $meBody = $client->getResponse()->toArray(false);
        $twoFactor = $meBody['twoFactor'];
        $this->assertIsArray($twoFactor);
        $this->assertFalse($twoFactor['recoveryPending']);
    }

    public function testReenrollWithoutRecoveryStillNeedsTotp(): void
    {
        // No backup-code login here — the user came in fresh with an
        // active authenticator. The verifier must still hold the TOTP
        // line; password alone is not enough.
        $user = $this->seedUser('still@example.com', 'Password123!@#');
        $this->enableTwoFactor($user);

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('POST', '/me/2fa/reenroll', [
            'json' => ['currentPassword' => 'Password123!@#'],
        ]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertTrue($this->reloadUser('still@example.com')->isTotpEnabled());
    }

    // --- Helpers ---

    /** @return string[] plaintext recovery codes */
    private function seedTwoFactorUser(string $email, string $password): array
    {
        $user = $this->seedUser($email, $password);
        return $this->enableTwoFactor($user);
    }

    private function seedUser(string $email, string $password): User
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
