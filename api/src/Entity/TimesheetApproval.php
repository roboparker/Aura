<?php

namespace App\Entity;

use App\Repository\TimesheetApprovalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A member's weekly timesheet approval (#654): one row per
 * (space, user, weekStart). While `pending` or `approved`, every time entry
 * the user started in that week is frozen (create/edit/delete 422) — the
 * same lock model as billed entries. A rejection unlocks the week (with a
 * note back to the member); re-submitting flips the row back to pending.
 *
 * Server-written only (submit / approve / reject endpoints in
 * {@see \App\Controller\TimesheetController}); not an API resource.
 */
#[ORM\Entity(repositoryClass: TimesheetApprovalRepository::class)]
#[ORM\Table(name: 'timesheet_approval')]
#[ORM\UniqueConstraint(name: 'uniq_timesheet_approval_week', columns: ['space_id', 'user_id', 'week_start'])]
#[ORM\Index(columns: ['space_id', 'status'], name: 'idx_timesheet_approval_space_status')]
#[ORM\Index(columns: ['user_id'], name: 'idx_timesheet_approval_user')]
class TimesheetApproval
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    public const MAX_NOTE_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Space $space = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** Monday (UTC date) of the submitted week. */
    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $weekStart = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    /** Reviewer note — most useful on rejection ("split Friday's entry"). */
    #[ORM\Column(length: self::MAX_NOTE_LENGTH, nullable: true)]
    private ?string $note = null;

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
    }

    /** Pending + approved freeze the week; rejected unlocks it. */
    public function locksWeek(): bool
    {
        return self::STATUS_REJECTED !== $this->status;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSpace(): ?Space
    {
        return $this->space;
    }

    public function setSpace(?Space $space): self
    {
        $this->space = $space;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getWeekStart(): ?\DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function setWeekStart(?\DateTimeImmutable $weekStart): self
    {
        $this->weekStart = $weekStart;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(\DateTimeImmutable $submittedAt): self
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): self
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): self
    {
        $this->decidedBy = $decidedBy;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }
}
