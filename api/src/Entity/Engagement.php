<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
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
use App\Repository\EngagementRepository;
use App\State\EngagementBudgetProvider;
use App\State\EngagementCreatorProcessor;
use App\Validator\ValidEngagementAttachments;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A billable board (Harvest-style): the unit that time is tracked against and
 * invoiced from. It belongs to a {@see Client} and defines a set of
 * {@see EngagementCategory} rows (named work + hourly rate). Time entries select a
 * engagement + one of its categories, which fixes the rate. Task-management
 * {@see Board}s can be assigned to a engagement (their `engagement` FK).
 *
 * Space-scoped and admin-managed like {@see Client}: the `invoices` permission
 * category gates create/read/update/delete via {@see \App\Doctrine\EngagementAccessExtension}.
 * Members select from it through the minimal picker
 * ({@see \App\Controller\EngagementOptionsController}).
 */
#[ApiResource(
    shortName: 'Engagement',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            provider: EngagementBudgetProvider::class,
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace() !== null and object.getSpace().hasMember(user) and is_granted('space.invoices.create', object)))",
            processor: EngagementCreatorProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.invoices.read', object)))",
            provider: EngagementBudgetProvider::class,
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.invoices.update', object)))",
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.invoices.delete', object)))",
        ),
    ],
    normalizationContext: ['groups' => ['engagement:read']],
    denormalizationContext: ['groups' => ['engagement:write']],
    order: ['name' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact', 'client' => 'exact', 'name' => 'partial'])]
#[ApiFilter(BooleanFilter::class, properties: ['archived'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
#[ORM\Entity(repositoryClass: EngagementRepository::class)]
#[ORM\Table(name: 'engagement')]
#[ORM\Index(columns: ['space_id', 'name'], name: 'idx_engagement_space_name')]
#[ORM\Index(columns: ['client_id'], name: 'idx_engagement_client')]
#[ORM\HasLifecycleCallbacks]
#[Assert\Callback('validateConsistency')]
#[ValidEngagementAttachments]
class Engagement
{
    public const MAX_NAME_LENGTH = 200;

    public const BUDGET_HOURS = 'hours';
    public const BUDGET_FEES = 'fees';

    /** @var list<string> */
    public const BUDGET_TYPES = [self::BUDGET_HOURS, self::BUDGET_FEES];

    /** Percent thresholds the nightly sweep alerts on, in firing order. */
    public const BUDGET_ALERT_THRESHOLDS = [80, 100];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['engagement:read', 'time_entry:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    #[Groups(['engagement:read', 'engagement:write'])]
    private ?Space $space = null;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'A client is required.')]
    #[Groups(['engagement:read', 'engagement:write', 'time_entry:read'])]
    private ?Client $client = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    #[Assert\NotBlank(message: 'A board name is required.')]
    #[Assert\Length(max: self::MAX_NAME_LENGTH)]
    #[Groups(['engagement:read', 'engagement:write', 'time_entry:read'])]
    private string $name = '';

    /** ISO 4217 currency for every category rate; defaults to the client's on create. */
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    #[Assert\Currency]
    #[Groups(['engagement:read', 'engagement:write', 'time_entry:read'])]
    private ?string $currency = null;

    /** Optional long-form notes (markdown) — scope, terms, key contacts. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['engagement:read', 'engagement:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['engagement:read', 'engagement:write'])]
    private bool $archived = false;

    /**
     * Budget (#651): `hours` tracks total tracked time against
     * {@see $budgetAmount} in minutes; `fees` tracks the billable amount
     * against it in minor units of {@see $currency}. Null = no budget.
     */
    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Choice(choices: self::BUDGET_TYPES, message: 'Invalid budget type.')]
    #[Groups(['engagement:read', 'engagement:write'])]
    private ?string $budgetType = null;

    /** Minutes for an hours budget, minor units for a fees budget. */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Positive(message: 'The budget must be positive.')]
    #[Groups(['engagement:read', 'engagement:write'])]
    private ?int $budgetAmount = null;

    /**
     * Thresholds (percent) already alerted — the idempotency ledger the
     * nightly sweep checks. Cleared whenever the budget itself changes so a
     * raised budget re-alerts when re-crossed.
     *
     * @var list<int>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['engagement:read'])]
    private ?array $budgetAlertsSent = null;

    /**
     * Transient (#651): consumption against the budget, set per-read by
     * {@see EngagementBudgetProvider} — minutes for hours budgets, minor
     * units for fees. Null when no budget is set.
     */
    #[ApiProperty(writable: false)]
    #[Groups(['engagement:read'])]
    private ?int $budgetSpent = null;

    /**
     * @var Collection<int, EngagementCategory>
     */
    #[ORM\OneToMany(
        mappedBy: 'engagement',
        targetEntity: EngagementCategory::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    #[Groups(['engagement:read', 'engagement:write'])]
    private Collection $categories;

    /**
     * Per-person billable rate overrides (#653) — when a user has a row
     * here, their tracked time snapshots this rate instead of the
     * category's. Written as part of the engagement like categories.
     *
     * @var Collection<int, EngagementUserRate>
     */
    #[ORM\OneToMany(
        mappedBy: 'engagement',
        targetEntity: EngagementUserRate::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[Groups(['engagement:read', 'engagement:write'])]
    private Collection $userRates;

    /**
     * Task-management boards assigned to this engagement (inverse of
     * {@see Board::$engagement}). Read-only summary for the detail page.
     *
     * @var Collection<int, Board>
     */
    #[ORM\OneToMany(mappedBy: 'engagement', targetEntity: Board::class)]
    private Collection $assignedProjects;

    /**
     * Contract + supporting files attached to this engagement. Same upload
     * flow as {@see Space::$attachments}: PATCH an `attachments` array of
     * MediaObject IRIs after POST /media-objects (kind=attachment).
     * {@see ValidEngagementAttachments} enforces uploader-is-member + kind.
     *
     * @var Collection<int, MediaObject>
     */
    #[ORM\ManyToMany(targetEntity: MediaObject::class)]
    #[ORM\JoinTable(name: 'engagement_attachment')]
    #[ORM\JoinColumn(name: 'engagement_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'media_object_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['engagement:read', 'engagement:write'])]
    private Collection $attachments;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['engagement:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['engagement:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['engagement:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->userRates = new ArrayCollection();
        $this->assignedProjects = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * The billable rate override for a user (#653), or null when they bill
     * at the category rate. UUID-based comparison so it survives EM::clear().
     */
    public function getUserRateFor(User $user): ?int
    {
        foreach ($this->userRates as $rate) {
            if (true === $rate->getUser()?->getId()?->equals($user->getId())) {
                return $rate->getRateAmount();
            }
        }

        return null;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function validateConsistency(ExecutionContextInterface $context): void
    {
        if (
            null !== $this->client && null !== $this->space
            && true !== $this->client->getSpace()?->getId()?->equals($this->space->getId())
        ) {
            $context->buildViolation('Client must belong to the same space.')
                ->atPath('client')
                ->addViolation();
        }

        $seen = [];
        foreach ($this->categories as $i => $category) {
            $key = mb_strtolower(trim($category->getName()));
            if ('' === $key) {
                continue;
            }
            if (isset($seen[$key])) {
                $context->buildViolation('Category names must be unique.')
                    ->atPath(sprintf('categories[%d].name', $i))
                    ->addViolation();
            }
            $seen[$key] = true;
        }

        // Budget type and amount come as a pair (#651).
        if (null !== $this->budgetType && null === $this->budgetAmount) {
            $context->buildViolation('A budget needs an amount.')
                ->atPath('budgetAmount')
                ->addViolation();
        }
        if (null !== $this->budgetAmount && null === $this->budgetType) {
            $context->buildViolation('A budget amount needs a type (hours or fees).')
                ->atPath('budgetType')
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

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;

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

    /**
     * @return Collection<int, MediaObject>
     */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(MediaObject $media): self
    {
        if (!$this->attachments->contains($media)) {
            $this->attachments->add($media);
        }

        return $this;
    }

    public function removeAttachment(MediaObject $media): self
    {
        $this->attachments->removeElement($media);

        return $this;
    }

    public function getBudgetType(): ?string
    {
        return $this->budgetType;
    }

    public function setBudgetType(?string $budgetType): self
    {
        if ($budgetType !== $this->budgetType) {
            $this->budgetAlertsSent = null;
        }
        $this->budgetType = $budgetType;

        return $this;
    }

    public function getBudgetAmount(): ?int
    {
        return $this->budgetAmount;
    }

    public function setBudgetAmount(?int $budgetAmount): self
    {
        if ($budgetAmount !== $this->budgetAmount) {
            $this->budgetAlertsSent = null;
        }
        $this->budgetAmount = $budgetAmount;

        return $this;
    }

    /** @return list<int> */
    public function getBudgetAlertsSent(): array
    {
        return $this->budgetAlertsSent ?? [];
    }

    public function addBudgetAlertSent(int $threshold): self
    {
        $log = $this->getBudgetAlertsSent();
        if (!in_array($threshold, $log, true)) {
            $log[] = $threshold;
            $this->budgetAlertsSent = $log;
        }

        return $this;
    }

    public function getBudgetSpent(): ?int
    {
        return $this->budgetSpent;
    }

    public function setBudgetSpent(?int $budgetSpent): self
    {
        $this->budgetSpent = $budgetSpent;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): self
    {
        $this->archived = $archived;

        return $this;
    }

    /**
     * @return Collection<int, EngagementCategory>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(EngagementCategory $category): self
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $category->setEngagement($this);
        }

        return $this;
    }

    public function removeCategory(EngagementCategory $category): self
    {
        $this->categories->removeElement($category);

        return $this;
    }

    /**
     * @return Collection<int, EngagementUserRate>
     */
    public function getUserRates(): Collection
    {
        return $this->userRates;
    }

    public function addUserRate(EngagementUserRate $userRate): self
    {
        if (!$this->userRates->contains($userRate)) {
            $this->userRates->add($userRate);
            $userRate->setEngagement($this);
        }

        return $this;
    }

    public function removeUserRate(EngagementUserRate $userRate): self
    {
        $this->userRates->removeElement($userRate);

        return $this;
    }

    /**
     * @return Collection<int, Board>
     */
    public function getAssignedProjects(): Collection
    {
        return $this->assignedProjects;
    }

    /**
     * Flattened task-board roster for the detail page (avoids embedding the
     * full Board resource + a serialization group on it).
     *
     * @return list<array{id: string, title: string}>
     */
    #[Groups(['engagement:read'])]
    public function getAssignedProjectList(): array
    {
        $out = [];
        foreach ($this->assignedProjects as $board) {
            $out[] = ['id' => (string) $board->getId(), 'title' => $board->getTitle()];
        }

        return $out;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

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
}
