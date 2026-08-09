<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RestoreTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The single-use credential behind a "restore" link emailed when an
 * organization, space, or account is deleted.
 *
 * Hashed like {@see PasswordResetToken} — the plaintext exists only in the
 * email — and **polymorphic by (targetType, targetId) rather than three FKs**.
 * A real FK would be worse here, not better: an account's token has to survive
 * the account being unable to sign in, and the row is meaningless once the
 * purge runs, so a cascade would delete the audit of what was restorable
 * anyway. The purge prunes spent tokens explicitly.
 *
 * The token alone is sufficient to restore — no sign-in required. That's
 * deliberate: an account holder in the grace period *cannot* sign in (that's
 * the point of the window), so requiring it would make the link useless for the
 * one case that needs it most. The exposure is bounded by restore being
 * non-destructive: the worst a leaked link does is bring back something its
 * owner wanted gone, which they can simply delete again.
 */
#[ORM\Entity(repositoryClass: RestoreTokenRepository::class)]
#[ORM\Table(name: 'restore_token')]
// Named explicitly so the migration and the mapping agree — an auto-named
// unique index would drift from the DDL and fail doctrine:schema:validate.
// The unique index also serves the by-token lookup, so there's no separate
// non-unique index on the same column.
#[ORM\UniqueConstraint(name: 'uniq_restore_token_hash', columns: ['token_hash'])]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'idx_restore_token_target')]
class RestoreToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(length: 32)]
    private string $targetType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    /** Snapshot of the name/email at deletion time, for the landing page. */
    #[ORM\Column(length: 255)]
    private string $targetLabel;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $targetType,
        Uuid $targetId,
        string $targetLabel,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->targetLabel = $targetLabel;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): Uuid
    {
        return $this->targetId;
    }

    public function getTargetLabel(): string
    {
        return $this->targetLabel;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function markUsed(): static
    {
        $this->usedAt = new \DateTimeImmutable();

        return $this;
    }
}
