<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One requested GDPR export of a single user's own account data.
 *
 * Created by `POST /me/export`, then processed asynchronously by
 * {@see \App\MessageHandler\GenerateAccountExportHandler}: the handler builds
 * a zip archive of the requester's own content (profile, preferences, tasks,
 * boards, pages, comments, tags, API tokens, and the files
 * they uploaded), stores it under `app.account_export_dir`, stamps a download
 * token, and emails the requester a link.
 *
 * The download token follows the PasswordResetToken model: only the sha256
 * hash is persisted; the plaintext exists solely in the email. Tokens are
 * minted at completion time (not request time) so the plaintext never rides
 * through the message queue.
 *
 * Exports expire `app.account_export_retention_days` (default 7) days after
 * completion; {@see \App\Service\AccountExportPruner} deletes the zip and the
 * row on the nightly schedule. Rows that never complete (worker died, retries
 * exhausted) are reaped by the same pass once they age past the window.
 *
 * One export per user: when a build completes it supersedes any older export
 * of the same requester, so the newest archive always wins (mirrors the
 * one-export-per-space invariant on {@see SpaceExport}).
 */
#[ORM\Entity]
#[ORM\Table(name: 'account_export')]
#[ORM\UniqueConstraint(name: 'uniq_account_export_token_hash', columns: ['token_hash'])]
#[ORM\Index(columns: ['requested_by_id'], name: 'idx_account_export_requested_by')]
class AccountExport
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $requestedBy;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    /**
     * sha256 of the download token; null until the export completes.
     * Postgres allows multiple NULLs under the unique constraint, so
     * in-flight rows don't collide.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenHash = null;

    /** Absolute path of the finished zip on disk; null until completed. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(User $requestedBy)
    {
        $this->requestedBy = $requestedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getRequestedBy(): User
    {
        return $this->requestedBy;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): void
    {
        $this->tokenHash = $tokenHash;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): void
    {
        $this->filePath = $filePath;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): void
    {
        $this->fileSize = $fileSize;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): void
    {
        $this->completedAt = $completedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function isDownloadable(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        return self::STATUS_COMPLETED === $this->status
            && null !== $this->expiresAt
            && $this->expiresAt > $now;
    }
}
