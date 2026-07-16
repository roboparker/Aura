<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\TimeEntryRepository;
use App\State\TimeEntryDeleteProcessor;
use App\State\TimeEntryUserProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A billable (or non-billable) chunk of tracked time (#444). The atomic unit of
 * time tracking: it records who spent how long on what, optionally tied to a
 * board and/or task, at an optional hourly rate. Once pulled onto an invoice
 * (#445) it is locked via {@see $billedAt} so the same time can't be billed twice.
 *
 * A running timer is a TimeEntry with {@see $endedAt} still null; a partial
 * unique index (added in the migration) enforces at most one running entry per
 * user. {@see $durationSeconds} is derived server-side from started/ended.
 *
 * Space-scoped like the rest of the content model: `space` is required and drives
 * {@see App\Doctrine\TimeEntryAccessExtension}. Any member may track their own
 * time (the `time_entries` permission category is on by default); edit/delete is
 * owner-or-admin.
 */
#[ApiResource(
    shortName: 'TimeEntry',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            // The week timesheet grid fetches a whole week in one request —
            // let clients raise the page size (capped globally at 100).
            paginationClientItemsPerPage: true,
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace() !== null and object.getSpace().hasMember(user) and is_granted('space.time_entries.create', object)))",
            processor: TimeEntryUserProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.time_entries.read', object)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getUser() == user or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.time_entries.update', object)))",
            processor: TimeEntryUserProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getUser() == user or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.time_entries.delete', object)))",
            processor: TimeEntryDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['time_entry:read']],
    denormalizationContext: ['groups' => ['time_entry:write']],
    order: ['startedAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact', 'engagement' => 'exact', 'category' => 'exact', 'user' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['billable'])]
#[ApiFilter(ExistsFilter::class, properties: ['endedAt'])]
#[ApiFilter(DateFilter::class, properties: ['startedAt'])]
#[ApiFilter(OrderFilter::class, properties: ['startedAt', 'createdAt'])]
#[ORM\Entity(repositoryClass: TimeEntryRepository::class)]
#[ORM\Table(name: 'time_entry')]
#[ORM\Index(columns: ['space_id', 'started_at'], name: 'idx_time_entry_space_started')]
#[ORM\Index(columns: ['user_id', 'started_at'], name: 'idx_time_entry_user_started')]
#[ORM\Index(columns: ['engagement_id'], name: 'idx_time_entry_engagement')]
#[ORM\Index(columns: ['category_id'], name: 'idx_time_entry_category')]
// Partial unique index (at most one running timer per user). Declared here with
// the `where` option so doctrine:schema:validate matches the migration's
// CREATE UNIQUE INDEX ... WHERE ended_at IS NULL (mirrors Space's personal-space index).
#[ORM\UniqueConstraint(
    name: 'uniq_time_entry_running_per_user',
    columns: ['user_id'],
    options: ['where' => '(ended_at IS NULL)'],
)]
#[ORM\HasLifecycleCallbacks]
#[Assert\Callback('validateConsistency')]
class TimeEntry
{
    public const MAX_DESCRIPTION_LENGTH = 1000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['time_entry:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    #[Groups(['time_entry:read', 'time_entry:write'])]
    private ?Space $space = null;

    /**
     * The engagement this time is tracked against (Harvest model). Required.
     * Carries the client; the {@see $category} (which must belong to it) fixes the rate.
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Engagement::class)]
    #[ORM\JoinColumn(name: 'engagement_id', nullable: true, onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'A engagement is required.')]
    #[Groups(['time_entry:read', 'time_entry:write'])]
    private ?Engagement $engagement = null;

    /** The category on the engagement — sets the hourly rate. Required. */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: EngagementCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true, onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'A category is required.')]
    #[Groups(['time_entry:read', 'time_entry:write'])]
    private ?EngagementCategory $category = null;

    /** The user who tracked the time. Stamped server-side, never from the payload. */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['time_entry:read'])]
    private ?User $user = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: self::MAX_DESCRIPTION_LENGTH)]
    #[Groups(['time_entry:read', 'time_entry:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull(message: 'A start time is required.')]
    #[Groups(['time_entry:read', 'time_entry:write'])]
    private ?\DateTimeImmutable $startedAt = null;

    /** Null while the timer is running. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['time_entry:read', 'time_entry:write'])]
    private ?\DateTimeImmutable $endedAt = null;

    /** Derived from started/ended on persist; null while running. */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['time_entry:read'])]
    private ?int $durationSeconds = null;

    /** Derived from the {@see $category}'s billability on save — not client-set. */
    #[ORM\Column(type: 'boolean')]
    #[Groups(['time_entry:read'])]
    private bool $billable = true;

    /**
     * Hourly rate in minor units, snapshotted from the {@see $category} on save
     * so a later rate change doesn't retroactively alter logged (or billed) time.
     * Derived, read-only.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['time_entry:read'])]
    private ?int $rateAmount = null;

    /** Currency of {@see $rateAmount}, snapshotted from the engagement. Read-only. */
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    #[Assert\Currency]
    #[Groups(['time_entry:read'])]
    private ?string $rateCurrency = null;

    /**
     * Internal cost rate (#653), snapshotted from the member's space-level
     * cost rate on save — profitability reports subtract it from billable
     * amounts. Derived, read-only, never exposed to non-invoicing members
     * (it's not in any read group; reports are the read surface).
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $costRateAmount = null;

    /** Set when the entry is pulled onto an invoice; locks it against re-billing. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['time_entry:read'])]
    private ?\DateTimeImmutable $billedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['time_entry:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['time_entry:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Denormalise the space from the engagement (so the access extension can
     * scope by `space` alone), snapshot the rate from the category, and derive
     * duration from started/ended.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function syncDerived(): void
    {
        if (null === $this->space && null !== $this->engagement) {
            $this->space = $this->engagement->getSpace();
        }

        // Snapshot the rate + billability from the category + board currency so a
        // later category change never rewrites already-logged (or billed) time.
        if (null !== $this->category) {
            $this->rateAmount = $this->category->getRateAmount();
            // #653: a per-person engagement rate overrides the category rate.
            if (null !== $this->engagement && null !== $this->user) {
                $override = $this->engagement->getUserRateFor($this->user);
                if (null !== $override) {
                    $this->rateAmount = $override;
                }
            }
            $this->rateCurrency = $this->engagement?->getCurrency();
            $this->billable = $this->category->isBillable();
        }

        // #653: snapshot the member's space-level cost rate for profitability.
        if (null !== $this->space && null !== $this->user) {
            $this->costRateAmount = $this->space->getCostRateFor($this->user);
        }

        $this->durationSeconds = null;
        if (null !== $this->startedAt && null !== $this->endedAt) {
            $this->durationSeconds = max(0, $this->endedAt->getTimestamp() - $this->startedAt->getTimestamp());
        }
    }

    public function validateConsistency(ExecutionContextInterface $context): void
    {
        if (null !== $this->startedAt && null !== $this->endedAt && $this->endedAt < $this->startedAt) {
            $context->buildViolation('End time must be after the start time.')
                ->atPath('endedAt')
                ->addViolation();
        }

        // No overnight entries — start and end must fall on the same calendar day.
        // Work spanning midnight is logged as two entries.
        if (
            null !== $this->startedAt && null !== $this->endedAt
            && $this->startedAt->format('Y-m-d') !== $this->endedAt->format('Y-m-d')
        ) {
            $context->buildViolation('A time entry cannot span more than one day. Log separate entries per day.')
                ->atPath('endedAt')
                ->addViolation();
        }

        // The category must belong to the selected engagement.
        if (
            null !== $this->category && null !== $this->engagement
            && true !== $this->category->getEngagement()?->getId()?->equals($this->engagement->getId())
        ) {
            $context->buildViolation('Category must belong to the selected engagement.')
                ->atPath('category')
                ->addViolation();
        }

        // The engagement must live in the entry's space.
        if (
            null !== $this->engagement && null !== $this->space
            && true !== $this->engagement->getSpace()?->getId()?->equals($this->space->getId())
        ) {
            $context->buildViolation('Engagement must belong to the same space.')
                ->atPath('engagement')
                ->addViolation();
        }
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

    public function getEngagement(): ?Engagement
    {
        return $this->engagement;
    }

    public function setEngagement(?Engagement $engagement): self
    {
        $this->engagement = $engagement;

        return $this;
    }

    public function getCategory(): ?EngagementCategory
    {
        return $this->category;
    }

    public function setCategory(?EngagementCategory $category): self
    {
        $this->category = $category;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): self
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function isRunning(): bool
    {
        return null === $this->endedAt;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function isBillable(): bool
    {
        return $this->billable;
    }

    public function setBillable(bool $billable): self
    {
        $this->billable = $billable;

        return $this;
    }

    public function getRateAmount(): ?int
    {
        return $this->rateAmount;
    }

    public function setRateAmount(?int $rateAmount): self
    {
        $this->rateAmount = $rateAmount;

        return $this;
    }

    public function getRateCurrency(): ?string
    {
        return $this->rateCurrency;
    }

    public function setRateCurrency(?string $rateCurrency): self
    {
        $this->rateCurrency = $rateCurrency;

        return $this;
    }

    public function getBilledAt(): ?\DateTimeImmutable
    {
        return $this->billedAt;
    }

    public function setBilledAt(?\DateTimeImmutable $billedAt): self
    {
        $this->billedAt = $billedAt;

        return $this;
    }

    public function getCostRateAmount(): ?int
    {
        return $this->costRateAmount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
