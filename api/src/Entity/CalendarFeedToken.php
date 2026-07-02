<?php

namespace App\Entity;

use App\Repository\CalendarFeedTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The private, unguessable token that authorises one user's read-only iCal
 * calendar feed (`GET /calendar/feed/{token}.ics`). Calendar clients (Google /
 * Outlook / Apple) can't log in, so the feed authenticates purely on this
 * bearer-in-the-URL secret.
 *
 * There is at most one token per user (unique FK). It follows the
 * PasswordResetToken / export-token model: only the sha256 hash is persisted,
 * and the plaintext exists solely in the subscribe URL handed back once at
 * generation time. Regenerating rotates the hash, so the old URL stops
 * resolving; revoking deletes the row. Neither the API nor the DB can ever
 * re-derive the plaintext, so a leaked database yields no working feed URLs.
 */
#[ORM\Entity(repositoryClass: CalendarFeedTokenRepository::class)]
#[ORM\Table(name: 'calendar_feed_token')]
#[ORM\UniqueConstraint(name: 'uniq_calendar_feed_token_hash', columns: ['token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_calendar_feed_token_user', columns: ['user_id'])]
class CalendarFeedToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** sha256 of the plaintext feed token. */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Stamped each time the feed is fetched, for the "last synced" hint. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAccessedAt = null;

    public function __construct(User $user, string $tokenHash)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
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

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /** Rotate to a new hash and reset the creation timestamp (regenerate). */
    public function rotate(string $tokenHash): void
    {
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastAccessedAt = null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastAccessedAt(): ?\DateTimeImmutable
    {
        return $this->lastAccessedAt;
    }

    public function markAccessed(): void
    {
        $this->lastAccessedAt = new \DateTimeImmutable();
    }
}
