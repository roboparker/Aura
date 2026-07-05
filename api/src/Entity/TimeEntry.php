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
use App\State\TimeEntryUserProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A billable (or non-billable) chunk of tracked time (#444). The atomic unit of
 * time tracking: it records who spent how long on what, optionally tied to a
 * project and/or task, at an optional hourly rate. Once pulled onto an invoice
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
        ),
    ],
    normalizationContext: ['groups' => ['time_entry:read']],
    denormalizationContext: ['groups' => ['time_entry:write']],
    order: ['startedAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact', 'billingProject' => 'exact', 'category' => 'exact', 'user' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['billable'])]
#[ApiFilter(ExistsFilter::class, properties: ['endedAt'])]
#[ApiFilter(DateFilter::class, properties: ['startedAt'])]
#[ApiFilter(OrderFilter::class, properties: ['startedAt', 'createdAt'])]
#[ORM\Entity(repositoryClass: TimeEntryRepository::class)]
#[ORM\Table(name: 'time_entry')]
#[ORM\Index(columns: ['space_id', 'started_at'], name: 'idx_time_entry_space_started')]
#[ORM\Index(columns: ['user_id', 'started_at'], name: 'idx_time_entry_user_started')]
#[ORM\Index(columns: ['billing_project_id'], name: 'idx_time_entry_billing_project')]
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
     * The billing project this time is tracked against (Harvest model). Required.
     * Carries the client; the {@see $category} (which must belong to it) fixes the rate.
     */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: BillingProject::class)]
    #[ORM\JoinColumn(name: 'billing_project_id', nullable: true, onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'A billing project is required.')]
    #[Groups(['time_entry:read', 'time_entry:write'])]
    private ?BillingProject $billingProject = null;

    /** The category on the billing project — sets the hourly rate. Required. */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: BillingCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true, onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'A category is required.')]
    #[Groups(['time_entry:read', 'time_entry:write'])]
    private ?BillingCategory $category = null;

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

    #[ORM\Column(type: 'boolean')]
    #[Groups(['time_entry:read', 'time_entry:write'])]
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

    /** Currency of {@see $rateAmount}, snapshotted from the billing project. Read-only. */
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    #[Assert\Currency]
    #[Groups(['time_entry:read'])]
    private ?string $rateCurrency = null;

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
     * Denormalise the space from the billing project (so the access extension can
     * scope by `space` alone), snapshot the rate from the category, and derive
     * duration from started/ended.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function syncDerived(): void
    {
        if (null === $this->space && null !== $this->billingProject) {
            $this->space = $this->billingProject->getSpace();
        }

        // Snapshot the rate from the category + project currency so a later rate
        // change never rewrites already-logged (or billed) time.
        if (null !== $this->category) {
            $this->rateAmount = $this->category->getRateAmount();
            $this->rateCurrency = $this->billingProject?->getCurrency();
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

        // The category must belong to the selected billing project.
        if (
            null !== $this->category && null !== $this->billingProject
            && true !== $this->category->getBillingProject()?->getId()?->equals($this->billingProject->getId())
        ) {
            $context->buildViolation('Category must belong to the selected billing project.')
                ->atPath('category')
                ->addViolation();
        }

        // The billing project must live in the entry's space.
        if (
            null !== $this->billingProject && null !== $this->space
            && true !== $this->billingProject->getSpace()?->getId()?->equals($this->space->getId())
        ) {
            $context->buildViolation('Billing project must belong to the same space.')
                ->atPath('billingProject')
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

    public function getBillingProject(): ?BillingProject
    {
        return $this->billingProject;
    }

    public function setBillingProject(?BillingProject $billingProject): self
    {
        $this->billingProject = $billingProject;

        return $this;
    }

    public function getCategory(): ?BillingCategory
    {
        return $this->category;
    }

    public function setCategory(?BillingCategory $category): self
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
