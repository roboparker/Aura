<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Service\AvatarColorService;
use App\State\UserPasswordHasherProcessor;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        // POST accepts both `user:create` (email, plainPassword, inviteToken)
        // and `user:write` so signup can set everything in one shot.
        new Post(
            processor: UserPasswordHasherProcessor::class,
            denormalizationContext: ['groups' => ['user:write', 'user:create']],
        ),
        new Get(security: "is_granted('ROLE_ADMIN') or object == user"),
        // PATCH only sees `user:write` — email changes go through
        // EmailChangeController so the new address can be verified.
        new Patch(security: "is_granted('ROLE_ADMIN') or object == user"),
    ],
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:write']],
)]
#[ORM\Entity]
#[ORM\Table(name: '`user`')]
#[ORM\Index(columns: ['avatar_id'], name: 'idx_user_avatar')]
#[UniqueEntity('email', message: 'This email is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['user:read', 'project:read', 'group:read', 'task:read', 'comment:read'])]
    private ?Uuid $id = null;

    // Settable on signup (`user:create`), but not on PATCH — changes
    // run through EmailChangeController so the new address is verified.
    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read', 'user:create', 'project:read', 'group:read', 'task:read', 'comment:read'])]
    private string $email = '';

    #[ORM\Column]
    private string $password = '';

    #[Assert\NotBlank(groups: ['user:create'])]
    #[Assert\Length(min: 6, minMessage: 'Password must be at least {{ limit }} characters.')]
    #[Groups(['user:create'])]
    private ?string $plainPassword = null;

    /**
     * Transient token from a UserInvite. When the signup payload includes
     * one, UserPasswordHasherProcessor resolves it after persisting the new
     * user and joins them to the invited group. Never stored.
     */
    #[Groups(['user:create'])]
    private ?string $inviteToken = null;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    #[Groups(['user:read'])]
    private array $roles = [];

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Given name is required.')]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read', 'task:read', 'comment:read'])]
    private string $givenName = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Family name is required.')]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read', 'task:read', 'comment:read'])]
    private string $familyName = '';

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read', 'task:read', 'comment:read'])]
    private ?string $nickname = null;

    /**
     * Hex color (e.g., "#1e6091") used behind the initials fallback when the
     * user has no avatar. Initialized to a random palette entry at
     * registration; the user can swap it for any other palette entry on
     * /account, but never to a free-form value (to keep contrast safe).
     */
    #[ORM\Column(length: 7)]
    #[Assert\Choice(
        choices: AvatarColorService::PALETTE,
        message: 'Pick a color from the available palette.',
    )]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read', 'task:read', 'comment:read'])]
    private string $personalizedColor = AvatarColorService::PALETTE[0];

    #[ORM\ManyToOne(targetEntity: MediaObject::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['user:write'])]
    private ?MediaObject $avatar = null;

    /**
     * Per-user preferences (theme, notification toggles, frequency). Stored
     * as a JSON object so we can extend the shape without a migration each
     * time. The canonical defaults live in {@see DEFAULT_PREFERENCES} and are
     * merged in by the getter, so older rows don't need backfilling and
     * partial PATCH updates Just Work.
     *
     * Mutated only via App\Controller\UserPreferencesController so the
     * allowed-keys + enum-values constraints stay enforced — never exposed
     * on the generic Patch operation above.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $preferences = null;

    /**
     * Encrypted TOTP secret (sodium secretbox over the base32 secret). Null
     * until the user enables 2FA. The plaintext is only ever returned once
     * — during the setup flow — so the user can scan it with an authenticator
     * app; after that we keep only the encrypted form.
     *
     * Decryption goes through {@see TwoFactorSecretCipher}, which derives a
     * key from APP_SECRET. The whole column is wrapped (vs. a hash) because
     * TOTP verification needs the original secret to recompute codes — there
     * is no equivalent of password hashing for TOTP.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $totpSecretEncrypted = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $totpEnabled = false;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    /**
     * Hashes (sha256) of the recovery codes still available for one-time
     * use during 2FA challenge. Marked used by removing the entry — the
     * column is the single source of truth for "remaining codes" so the
     * count surfaced to the user (and the dedupe in invalidateBackupCode)
     * stay in sync. Plaintext codes are only ever returned once at
     * generation time.
     *
     * @var string[]|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $totpRecoveryCodeHashes = null;

    public const ALLOWED_THEMES = ['light', 'dark', 'system'];
    public const ALLOWED_FREQUENCIES = ['realtime', 'hourly', 'daily'];
    public const DEFAULT_PREFERENCES = [
        'theme' => 'system',
        'emailNotificationsEnabled' => true,
        'pushNotificationsEnabled' => false,
        'notificationFrequency' => 'realtime',
    ];

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    public function getInviteToken(): ?string
    {
        return $this->inviteToken;
    }

    public function setInviteToken(?string $inviteToken): static
    {
        $this->inviteToken = $inviteToken;
        return $this;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    /** @param string[] $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getGivenName(): string
    {
        return $this->givenName;
    }

    public function setGivenName(string $givenName): static
    {
        $this->givenName = $givenName;
        return $this;
    }

    public function getFamilyName(): string
    {
        return $this->familyName;
    }

    public function setFamilyName(string $familyName): static
    {
        $this->familyName = $familyName;
        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): static
    {
        $this->nickname = $nickname;
        return $this;
    }

    public function getPersonalizedColor(): string
    {
        return $this->personalizedColor;
    }

    public function setPersonalizedColor(string $personalizedColor): static
    {
        $this->personalizedColor = $personalizedColor;
        return $this;
    }

    public function getAvatar(): ?MediaObject
    {
        return $this->avatar;
    }

    public function setAvatar(?MediaObject $avatar): static
    {
        $this->avatar = $avatar;
        return $this;
    }

    /**
     * Avatar variant URLs keyed by size ("thumb", "profile"). Null when the
     * user has no avatar — the frontend falls back to initials on
     * personalizedColor.
     *
     * @return array<string, string>|null
     */
    #[Groups(['user:read', 'project:read', 'group:read', 'task:read', 'comment:read'])]
    public function getAvatarUrls(): ?array
    {
        return $this->avatar?->getVariantUrls();
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
        $this->inviteToken = null;
    }

    /**
     * Always returns the full preference set, defaults merged in for any
     * keys the stored row is missing. Callers can read e.g.
     * `$user->getPreferences()['theme']` without null-checks.
     *
     * @return array<string, mixed>
     */
    public function getPreferences(): array
    {
        return array_merge(self::DEFAULT_PREFERENCES, $this->preferences ?? []);
    }

    /**
     * Replaces the stored preferences. Pass an already-validated, full
     * preference array (callers should merge over {@see getPreferences()}
     * for partial updates).
     *
     * @param array<string, mixed> $preferences
     */
    public function setPreferences(array $preferences): static
    {
        $this->preferences = $preferences;
        return $this;
    }

    // --- TOTP two-factor (Scheb) ---

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpEnabled && null !== $this->totpSecretEncrypted;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        $secret = $this->getDecryptedTotpSecret();
        if (null === $secret) {
            return null;
        }
        // Defaults match what Google Authenticator / Authy expect:
        // SHA1, 6-digit codes, 30-second window.
        return new TotpConfiguration($secret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    /**
     * Cached plaintext TOTP secret. Populated by
     * {@see App\EventListener\UserTotpCipherListener} on Doctrine postLoad
     * (and by {@see setTotpSecret()} at write time) so Scheb can pull a
     * configuration without round-tripping through the cipher every call.
     * Marked transient — no ORM mapping — so it never hits the database.
     */
    private ?string $totpSecretCache = null;

    public function getDecryptedTotpSecret(): ?string
    {
        return $this->totpSecretCache;
    }

    public function getTotpSecretEncrypted(): ?string
    {
        return $this->totpSecretEncrypted;
    }

    /**
     * Stores the encrypted form returned by {@see TwoFactorSecretCipher}.
     * Only the cipher service should produce the envelope; controllers
     * call {@see setTotpSecretPlain()} which delegates to the listener.
     */
    public function setTotpSecretEncrypted(?string $encrypted): static
    {
        $this->totpSecretEncrypted = $encrypted;
        return $this;
    }

    /**
     * Cache the plaintext for the lifetime of this hydrated entity. Called
     * by the cipher listener after load and after a write encryption.
     */
    public function setTotpSecretCache(?string $plaintextSecret): static
    {
        $this->totpSecretCache = $plaintextSecret;
        return $this;
    }

    public function isTotpEnabled(): bool
    {
        return $this->totpEnabled;
    }

    public function setTotpEnabled(bool $enabled): static
    {
        $this->totpEnabled = $enabled;
        $this->totpEnabledAt = $enabled ? new \DateTimeImmutable() : null;
        return $this;
    }

    public function getTotpEnabledAt(): ?\DateTimeImmutable
    {
        return $this->totpEnabledAt;
    }

    /** @param string[] $hashes */
    public function setRecoveryCodeHashes(array $hashes): static
    {
        // Re-index so the JSON column is always a list, never a sparse map
        // after invalidation removes mid-array entries.
        $this->totpRecoveryCodeHashes = array_values($hashes);
        return $this;
    }

    /** @return string[] */
    public function getRecoveryCodeHashes(): array
    {
        return $this->totpRecoveryCodeHashes ?? [];
    }

    public function getRecoveryCodeCount(): int
    {
        return count($this->getRecoveryCodeHashes());
    }

    // --- BackupCodeInterface (Scheb) ---

    public function isBackupCode(string $code): bool
    {
        $hash = hash('sha256', $code);
        return in_array($hash, $this->getRecoveryCodeHashes(), true);
    }

    public function invalidateBackupCode(string $code): void
    {
        $hash = hash('sha256', $code);
        $remaining = array_values(array_filter(
            $this->getRecoveryCodeHashes(),
            static fn (string $h) => $h !== $hash,
        ));
        $this->totpRecoveryCodeHashes = $remaining;
    }
}
