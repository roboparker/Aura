<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Service\AvatarColorService;
use App\State\UserPasswordHasherProcessor;
use App\Validator\PasswordPolicy;
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
    #[Groups(['user:read', 'project:read', 'group:read', 'task:read', 'comment:read', 'discussion:read', 'space:read', 'page:read', 'notification:read', 'segment:read'])]
    private ?Uuid $id = null;

    // Settable on signup (`user:create`), but not on PATCH — changes
    // run through EmailChangeController so the new address is verified.
    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read', 'user:create', 'project:read', 'group:read', 'task:read', 'comment:read', 'discussion:read', 'space:read', 'page:read', 'notification:read', 'segment:read'])]
    private string $email = '';

    #[ORM\Column]
    private string $password = '';

    #[Assert\NotBlank(groups: ['user:create'])]
    // NIST SP 800-63B: length wins over complexity rules. 8 chars is the
    // hard floor (matching `App\Controller\PasswordController::MIN_PASSWORD_LENGTH`).
    // PasswordPolicy reads the env-driven `app.password_min_strength`
    // parameter so dev/test can run at VERY_WEAK while staging/prod
    // stay at MEDIUM. The PWA meter mirrors the same floor via
    // `NEXT_PUBLIC_PASSWORD_MIN_STRENGTH` so meter and server agree.
    #[Assert\Length(min: 8, minMessage: 'Password must be at least {{ limit }} characters.')]
    #[PasswordPolicy]
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

    /**
     * When true the account is on the launch waitlist: it can sign in and
     * read /api/me but holds no ROLE_USER (see {@see getRoles()}), so every
     * ROLE_USER-gated resource + access extension blocks it without any
     * per-endpoint special-casing. Set server-side only — by
     * UserPasswordHasherProcessor at signup while waitlist mode is on, and
     * cleared in bulk by WaitlistPromoter when an admin opens signups. Never
     * placed in a serialization group, so it can't be toggled over the wire.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $waitlisted = false;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Given name is required.')]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read', 'task:read', 'comment:read', 'discussion:read', 'space:read', 'page:read', 'notification:read', 'segment:read'])]
    private string $givenName = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Family name is required.')]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read', 'task:read', 'comment:read', 'discussion:read', 'space:read', 'page:read', 'notification:read', 'segment:read'])]
    private string $familyName = '';

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Groups(['user:read', 'user:write', 'project:read', 'group:read', 'task:read', 'comment:read', 'discussion:read', 'space:read', 'page:read', 'notification:read', 'segment:read'])]
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
    #[Groups(['user:read', 'user:write', 'user:create', 'project:read', 'group:read', 'task:read', 'comment:read', 'discussion:read', 'space:read', 'page:read', 'notification:read', 'segment:read'])]
    private string $personalizedColor = AvatarColorService::PALETTE[0];

    /**
     * Set to true once the setter has been called, so
     * {@see \App\State\UserPasswordHasherProcessor} can tell a
     * user-supplied color apart from the entity's default. Transient
     * (not persisted) — every fresh request rebuilds the entity and the
     * flag goes back to false until a setter call flips it.
     */
    private bool $personalizedColorExplicit = false;

    #[ORM\ManyToOne(targetEntity: MediaObject::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['user:write'])]
    private ?MediaObject $avatar = null;

    /**
     * Per-user preferences (timezone, notification toggles, frequency). Stored
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
     * Last time a TOTP / backup code was accepted at the login challenge —
     * surfaced in the Settings security panel. Stamped by
     * {@see \App\EventSubscriber\TwoFactorVerifiedStamper} on the Scheb
     * SUCCESS event.
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $totpLastVerifiedAt = null;

    /**
     * Soft-deactivation timestamp (Settings → Danger zone). When set, all
     * sessions are revoked; signing back in clears it (reactivation) via
     * {@see \App\Security\UserChecker}.
     */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    /**
     * Recovery codes for the 2FA challenge.
     *
     * Each entry is `{hash, consumedAt, encrypted?, consumedCode?}`:
     * - `hash`: sha256 of the plaintext, the source of truth for verification.
     * - `consumedAt`: ATOM timestamp when the code was used during a 2FA
     *    challenge. Non-null entries are kept on the row so the security
     *    panel can show "consumed N of M" with strikethrough, but they're
     *    excluded from the "remaining" count and rejected on future
     *    challenges.
     * - `encrypted`: envelope-encrypted plaintext via {@see TwoFactorSecretCipher}
     *    so the GitHub-style "reveal codes" flow can re-display the full
     *    list after a fresh password challenge. Optional — older rows
     *    written before the reveal feature don't have it and surface as
     *    "(unavailable)" in the reveal view.
     * - `consumedCode`: legacy field — earlier iterations captured
     *    plaintext only on consumption. New writes use `encrypted` for
     *    everything; this stays so the in-place "consumed code" display
     *    still works for entries written in the interim. Read order:
     *    `encrypted` (decrypted) wins, `consumedCode` fallback.
     *
     * Plaintext for unused codes is a deliberate weakening of the old
     * hash-only model — a DB-leak + APP_SECRET-leak yields the unused
     * codes. Surface protections:
     *  - the reveal endpoint requires re-entering the current password
     *  - the panel never auto-reveals; the user has to opt in per session
     *  - consumed codes are safe regardless (hash check rejects them)
     *
     * @var list<array{
     *     hash: string,
     *     consumedAt: ?string,
     *     encrypted?: ?string,
     *     consumedCode?: ?string,
     * }>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $totpRecoveryCodes = null;

    public const ALLOWED_FREQUENCIES = ['realtime', 'hourly', 'daily'];
    public const NOTIFICATION_MATRIX_ROWS = [
        'mentions',
        'assigned',
        'comments',
        'replies',
        'status',
        'space-invites',
    ];

    /**
     * Post-login landing destinations for the "Start page" preference.
     * `space` lands in the workspace home with a chosen space active
     * (a null `spaceId` means the personal "Private" space).
     */
    public const LANDING_PAGES = ['tasks', 'notifications', 'spaces', 'space'];

    public const DEFAULT_PREFERENCES = [
        // IANA time-zone identifier (e.g. "America/New_York"). Anchors
        // scheduling + reminder display and digest/quiet-hours math.
        'timezone' => 'UTC',
        // Legacy master switches — kept as hard overrides over the matrix.
        'emailNotificationsEnabled' => true,
        'pushNotificationsEnabled' => false,
        'notificationFrequency' => 'realtime',
        // Per-event delivery matrix: row → {inApp, email}.
        'notificationMatrix' => [
            'mentions' => ['inApp' => true, 'email' => true],
            'assigned' => ['inApp' => true, 'email' => true],
            'comments' => ['inApp' => true, 'email' => false],
            'replies' => ['inApp' => true, 'email' => true],
            'status' => ['inApp' => true, 'email' => false],
            'space-invites' => ['inApp' => true, 'email' => true],
        ],
        // Email digest cadence (supersedes notificationFrequency).
        'emailDigest' => ['mode' => 'realtime', 'hour' => 8],
        // Quiet hours (interpreted in the user's timezone). Suppresses
        // email + push while active; in-app rows still land.
        'quietHours' => ['enabled' => false, 'start' => '22:00', 'end' => '07:00'],
        // Post-login landing ("Start page"). `page` ∈ LANDING_PAGES; for
        // 'space', `spaceId` names the space to activate (null = the
        // personal "Private" space). Default matches #405: land in the
        // workspace with the personal space active.
        'landing' => ['page' => 'space', 'spaceId' => null],
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
        // A waitlisted account is deliberately denied ROLE_USER: nearly
        // every resource (security expressions + access extensions) requires
        // it, so withholding it fails closed and boxes a waiting user into
        // sign-in + /api/me + the waitlist page. Promotion (WaitlistPromoter)
        // flips the flag and ROLE_USER returns on the next request.
        if ($this->waitlisted) {
            return ['ROLE_WAITLISTED'];
        }

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

    public function isWaitlisted(): bool
    {
        return $this->waitlisted;
    }

    public function setWaitlisted(bool $waitlisted): static
    {
        $this->waitlisted = $waitlisted;
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

    public function isPersonalizedColorExplicitlySet(): bool
    {
        return $this->personalizedColorExplicit;
    }

    public function setPersonalizedColor(string $personalizedColor): static
    {
        $this->personalizedColor = $personalizedColor;
        $this->personalizedColorExplicit = true;
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
    #[Groups(['user:read', 'project:read', 'group:read', 'task:read', 'comment:read', 'discussion:read', 'space:read', 'page:read', 'notification:read'])]
    public function getAvatarUrls(): ?array
    {
        return $this->avatar?->getVariantUrls();
    }

    public function getUserIdentifier(): string
    {
        // The Email + NotBlank validators guarantee a non-empty value by
        // the time a User is persisted; assert here so the
        // UserInterface's non-empty-string return contract is satisfied
        // even when phpstan can't see through the validation pipeline.
        assert('' !== $this->email);
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
     * `$user->getPreferences()['timezone']` without null-checks.
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

    public function getTotpLastVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->totpLastVerifiedAt;
    }

    public function setTotpLastVerifiedAt(?\DateTimeImmutable $at): static
    {
        $this->totpLastVerifiedAt = $at;
        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function setDeactivatedAt(?\DateTimeImmutable $at): static
    {
        $this->deactivatedAt = $at;
        return $this;
    }

    public function isDeactivated(): bool
    {
        return null !== $this->deactivatedAt;
    }

    /**
     * @param list<array{
     *     hash: string,
     *     consumedAt: ?string,
     *     encrypted?: ?string,
     *     consumedCode?: ?string,
     * }> $entries
     */
    public function setRecoveryCodes(array $entries): static
    {
        $this->totpRecoveryCodes = $entries;
        return $this;
    }

    /**
     * @return list<array{
     *     hash: string,
     *     consumedAt: ?string,
     *     encrypted?: ?string,
     *     consumedCode?: ?string,
     * }>
     */
    public function getRecoveryCodes(): array
    {
        return $this->totpRecoveryCodes ?? [];
    }

    /**
     * Count of recovery codes still available — consumed codes are kept on
     * the entity for display but excluded here so the "X remaining"
     * indicator and the regen-prompt threshold continue to work.
     */
    public function getRecoveryCodeCount(): int
    {
        $remaining = 0;
        foreach ($this->getRecoveryCodes() as $entry) {
            if (null === $entry['consumedAt']) {
                $remaining++;
            }
        }
        return $remaining;
    }

    // --- BackupCodeInterface (Scheb) ---

    public function isBackupCode(string $code): bool
    {
        $hash = hash('sha256', $code);
        foreach ($this->getRecoveryCodes() as $entry) {
            if ($entry['hash'] === $hash && null === $entry['consumedAt']) {
                return true;
            }
        }
        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $hash = hash('sha256', $code);
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $entries = $this->getRecoveryCodes();
        foreach ($entries as $index => $entry) {
            if ($entry['hash'] === $hash && null === $entry['consumedAt']) {
                $entries[$index]['consumedAt'] = $now;
                // No need to capture plaintext separately — new entries
                // carry an `encrypted` envelope from generation time that
                // the security panel decrypts for display. Legacy entries
                // (pre-encrypted, post-consumedCode) keep their own
                // capture untouched.
                $this->totpRecoveryCodes = $entries;
                return;
            }
        }
    }
}
