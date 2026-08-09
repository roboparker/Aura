<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminActionLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A record of a site admin acting destructively on somebody else's data.
 *
 * Deliberately a table rather than a log line. These are the most severe
 * actions in the product — permanently deleting an account, an organization, or
 * a space belonging to another person — and the question that gets asked
 * afterwards is always "who did this, when, and why". A log line answers that
 * only until retention rolls it away, and can't be surfaced in the admin UI.
 *
 * Every column is **snapshotted**, not referenced: the whole point is that the
 * row survives its target being destroyed. `actor` is the one real FK, and it's
 * SET NULL so the audit trail outlives the admin's own account too.
 */
#[ORM\Entity(repositoryClass: AdminActionLogRepository::class)]
#[ORM\Table(name: 'admin_action_log')]
#[ORM\Index(columns: ['created_at'], name: 'idx_admin_action_log_created')]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'idx_admin_action_log_target')]
// The FK's index is named explicitly here so it matches the migration —
// Doctrine would otherwise generate a hashed name and schema:validate would
// want to drop and recreate it.
#[ORM\Index(columns: ['actor_id'], name: 'IDX_ADMIN_ACTION_LOG_ACTOR')]
class AdminActionLog
{
    public const ACTION_DELETE_SCHEDULED = 'delete_scheduled';
    public const ACTION_DELETE_IMMEDIATE = 'delete_immediate';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /** The admin who acted. SET NULL so the trail outlives their account. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** Snapshot, so the row still names them after their account is gone. */
    #[ORM\Column(length: 180)]
    private string $actorEmail;

    #[ORM\Column(length: 32)]
    private string $action;

    #[ORM\Column(length: 32)]
    private string $targetType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    /** Snapshot of the name/email at the time of the action. */
    #[ORM\Column(length: 255)]
    private string $targetLabel;

    /** Whoever owned the target, snapshotted — usually not the actor. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $targetOwnerEmail = null;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\Column]
    private bool $ownerNotified = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ?User $actor,
        string $actorEmail,
        string $action,
        string $targetType,
        Uuid $targetId,
        string $targetLabel,
        string $reason,
    ) {
        $this->actor = $actor;
        $this->actorEmail = $actorEmail;
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->targetLabel = $targetLabel;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorEmail(): string
    {
        return $this->actorEmail;
    }

    public function getAction(): string
    {
        return $this->action;
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

    public function getTargetOwnerEmail(): ?string
    {
        return $this->targetOwnerEmail;
    }

    public function setTargetOwnerEmail(?string $email): static
    {
        $this->targetOwnerEmail = $email;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function wasOwnerNotified(): bool
    {
        return $this->ownerNotified;
    }

    public function setOwnerNotified(bool $notified): static
    {
        $this->ownerNotified = $notified;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
