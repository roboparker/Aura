<?php

namespace App\Entity;

use App\Repository\EmailChangeRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Tracks an in-flight email-change for a user. The flow has two phases:
 *
 *   1. **Request** — the user enters a new email; we email a confirm
 *      link to the *new* address. `confirmTokenHash` gates this step.
 *
 *   2. **Confirmation** — they click the link; the user's email is
 *      flipped to `newEmail`, `confirmedAt` is stamped, a fresh
 *      `revertTokenHash` is issued, and a notice is mailed to
 *      `oldEmail` with that revert link plus a reminder to reset
 *      their password if the change wasn't intentional.
 *
 * Plain tokens are never persisted — only sha256 hashes — same pattern
 * as PasswordResetToken and UserInvite.
 */
#[ORM\Entity(repositoryClass: EmailChangeRequestRepository::class)]
#[ORM\Table(name: 'email_change_request')]
#[ORM\Index(columns: ['confirm_token_hash'], name: 'idx_email_change_confirm_hash')]
#[ORM\Index(columns: ['revert_token_hash'], name: 'idx_email_change_revert_hash')]
class EmailChangeRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 180)]
    private string $oldEmail;

    #[ORM\Column(length: 180)]
    private string $newEmail;

    #[ORM\Column(length: 64, unique: true)]
    private string $confirmTokenHash;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $revertTokenHash = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revertExpiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revertedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        string $oldEmail,
        string $newEmail,
        string $confirmTokenHash,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->user = $user;
        $this->oldEmail = $oldEmail;
        $this->newEmail = $newEmail;
        $this->confirmTokenHash = $confirmTokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getOldEmail(): string
    {
        return $this->oldEmail;
    }

    public function getNewEmail(): string
    {
        return $this->newEmail;
    }

    public function getConfirmTokenHash(): string
    {
        return $this->confirmTokenHash;
    }

    public function getRevertTokenHash(): ?string
    {
        return $this->revertTokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevertExpiresAt(): ?\DateTimeImmutable
    {
        return $this->revertExpiresAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function getRevertedAt(): ?\DateTimeImmutable
    {
        return $this->revertedAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function markConfirmed(string $revertTokenHash, \DateTimeImmutable $revertExpiresAt): void
    {
        $this->confirmedAt = new \DateTimeImmutable();
        $this->revertTokenHash = $revertTokenHash;
        $this->revertExpiresAt = $revertExpiresAt;
    }

    public function markReverted(): void
    {
        $this->revertedAt = new \DateTimeImmutable();
    }

    public function cancel(): void
    {
        $this->cancelledAt = new \DateTimeImmutable();
    }

    /** Confirm token is still actionable. */
    public function isConfirmable(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        return null === $this->confirmedAt
            && null === $this->cancelledAt
            && $this->expiresAt > $now;
    }

    /** Revert token has been issued (post-confirm) and is still actionable. */
    public function isRevertable(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        return null !== $this->confirmedAt
            && null === $this->revertedAt
            && null !== $this->revertExpiresAt
            && $this->revertExpiresAt > $now;
    }
}
