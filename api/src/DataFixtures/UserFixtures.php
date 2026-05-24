<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Service\AvatarColorService;
use App\Service\TwoFactorSecretCipher;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public const ADMIN_REFERENCE = 'user-admin';
    public const USER_REFERENCE = 'user-uma';

    /**
     * Hardcoded TOTP secret for the Uma fixture user so dev / E2E flows can
     * pre-pair an authenticator once and reuse the same QR across fixture
     * reloads. Valid base32 (A-Z, 2-7), length matches what
     * {@see \Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface::generateSecret()}
     * produces in the wild. Not a secret in any meaningful sense — only
     * paired with the fixture user on fixture-only databases.
     */
    private const FIXTURE_TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /**
     * Stable recovery codes for the Uma fixture user. Mirrors the
     * "xxxx-xxxx-xxxx" hex shape produced by
     * {@see \App\Service\TwoFactorSetupService::regenerateRecoveryCodes()}.
     *
     * @var list<string>
     */
    private const FIXTURE_RECOVERY_CODES = [
        'aaaa-1111-bbbb',
        'cccc-2222-dddd',
        'eeee-3333-ffff',
        '1111-aaaa-2222',
        '2222-bbbb-3333',
        '3333-cccc-4444',
        '4444-dddd-5555',
        '5555-eeee-6666',
        '6666-ffff-7777',
        '7777-aaaa-8888',
    ];

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private AvatarColorService $colorService,
        private TwoFactorSecretCipher $totpCipher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = new User();
        $admin->setEmail('admin@aura.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setGivenName('Ada');
        $admin->setFamilyName('Admin');
        $admin->setPersonalizedColor($this->colorService->pick());
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $manager->persist($admin);

        $user = new User();
        $user->setEmail('user@aura.test');
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Uma');
        $user->setFamilyName('User');
        $user->setPersonalizedColor($this->colorService->pick());
        $user->setPassword($this->passwordHasher->hashPassword($user, 'user123'));

        // Pre-enable TOTP 2FA on Uma so the sign-in flow exercises the
        // challenge step without a manual pairing step every fixture reload.
        $user->setTotpSecretEncrypted($this->totpCipher->encrypt(self::FIXTURE_TOTP_SECRET));
        $user->setTotpSecretCache(self::FIXTURE_TOTP_SECRET);
        $user->setTotpEnabled(true);
        $user->setRecoveryCodes(array_map(
            static fn (string $code): array => [
                'hash' => hash('sha256', $code),
                'consumedAt' => null,
            ],
            self::FIXTURE_RECOVERY_CODES,
        ));

        $manager->persist($user);

        $manager->flush();

        $this->addReference(self::ADMIN_REFERENCE, $admin);
        $this->addReference(self::USER_REFERENCE, $user);
    }
}
