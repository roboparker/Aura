<?php

namespace App\DataFixtures;

use App\Entity\Space;
use App\Entity\SpaceMembership;
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
    public const TEAM_USER_REFERENCES = [
        'user-noah',
        'user-emma',
        'user-liam',
        'user-ava',
    ];

    /**
     * Demo team members beyond Uma. Same password hashed once at load
     * time to keep fixture reload time bounded — production hashing on
     * five users would dominate the load runtime otherwise.
     *
     * @var list<array{reference: string, email: string, given: string, family: string}>
     */
    private const TEAM_USERS = [
        ['reference' => 'user-noah', 'email' => 'noah@team.madori.test',  'given' => 'Noah',  'family' => 'Kim'],
        ['reference' => 'user-emma', 'email' => 'emma@team.madori.test',  'given' => 'Emma',  'family' => 'Reyes'],
        ['reference' => 'user-liam', 'email' => 'liam@team.madori.test',  'given' => 'Liam',  'family' => 'Patel'],
        ['reference' => 'user-ava',  'email' => 'ava@team.madori.test',   'given' => 'Ava',   'family' => 'Okafor'],
    ];

    /**
     * Demo opt-in to admin impersonation for the non-admin fixture users
     * (Uma + the team). Production accounts start with this OFF
     * ({@see User::DEFAULT_PREFERENCES}) — an admin can't switch into an
     * account until its owner consents — so the demo would otherwise show
     * "Couldn't switch user." on every attempt. Enabling consent + full
     * per-category access here makes the impersonation flow exercisable end
     * to end on a fixture DB. {@see setPreferences()} merges over the
     * defaults, so only these two keys need to be stored.
     *
     * @var array<string, mixed>
     */
    private const DEMO_IMPERSONATION_PREFS = [
        'canBeImpersonated' => true,
        'impersonationAccess' => [
            'tasks' => 'edit',
            'boards' => 'edit',
            'pages' => 'edit',
            'comments' => 'edit',
            'notifications' => 'edit',
            'files' => 'edit',
        ],
    ];

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
        $admin->setEmail('admin@madori.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setGivenName('Ada');
        $admin->setFamilyName('Admin');
        $admin->setPersonalizedColor($this->colorService->pick());
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $manager->persist($admin);

        $user = new User();
        $user->setEmail('user@madori.test');
        $user->setRoles(['ROLE_USER']);
        $user->setGivenName('Uma');
        $user->setFamilyName('User');
        $user->setPersonalizedColor($this->colorService->pick());
        $user->setPassword($this->passwordHasher->hashPassword($user, 'user123'));
        $user->setPreferences(self::DEMO_IMPERSONATION_PREFS);

        // Pre-enable TOTP 2FA on Uma so the sign-in flow exercises the
        // challenge step without a manual pairing step every fixture reload.
        $user->setTotpSecretEncrypted($this->totpCipher->encrypt(self::FIXTURE_TOTP_SECRET));
        $user->setTotpSecretCache(self::FIXTURE_TOTP_SECRET);
        $user->setTotpEnabled(true);
        $user->setRecoveryCodes(array_map(
            fn (string $code): array => [
                'hash' => hash('sha256', $code),
                'encrypted' => $this->totpCipher->encrypt($code),
                'consumedAt' => null,
            ],
            self::FIXTURE_RECOVERY_CODES,
        ));

        $manager->persist($user);

        // Hash the shared team password once and reuse — saves four bcrypt
        // rounds per fixture load.
        $teamPasswordHash = $this->passwordHasher->hashPassword($user, 'team123');
        $teamUsers = [];
        foreach (self::TEAM_USERS as $spec) {
            $member = new User();
            $member->setEmail($spec['email']);
            $member->setRoles(['ROLE_USER']);
            $member->setGivenName($spec['given']);
            $member->setFamilyName($spec['family']);
            $member->setPersonalizedColor($this->colorService->pick());
            $member->setPassword($teamPasswordHash);
            // Ava deliberately stays opted OUT of impersonation so the admin
            // users page demonstrates the disabled "this user has not enabled
            // impersonation" state alongside the opted-in members.
            if ('user-ava' !== $spec['reference']) {
                $member->setPreferences(self::DEMO_IMPERSONATION_PREFS);
            }
            $manager->persist($member);
            $teamUsers[$spec['reference']] = $member;
        }

        // Personal "Private" space for every fixture user. Production
        // signups get this from {@see UserPasswordHasherProcessor};
        // fixtures bypass that processor (we persist Users directly to
        // avoid hashing through the API layer), so we mirror the same
        // provisioning here to keep the dev DB shape honest.
        foreach ([$admin, $user, ...array_values($teamUsers)] as $u) {
            $this->createPersonalSpace($manager, $u);
        }

        $manager->flush();

        $this->addReference(self::ADMIN_REFERENCE, $admin);
        $this->addReference(self::USER_REFERENCE, $user);
        foreach ($teamUsers as $reference => $member) {
            $this->addReference($reference, $member);
        }
    }

    private function createPersonalSpace(ObjectManager $manager, User $user): void
    {
        $space = (new Space())
            ->setName(Space::PERSONAL_SPACE_NAME)
            ->setDescription('Your private space — only you can see what lives in here.')
            ->setIsPersonal(true)
            ->setVisibility(Space::VISIBILITY_PRIVATE)
            ->setCreatedBy($user);
        $space->addUserMembership(
            (new SpaceMembership())
                ->setUser($user)
                ->setRole(Space::ROLE_ADMIN),
        );
        $manager->persist($space);
    }
}
