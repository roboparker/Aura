<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
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
use App\Repository\ExpenseRepository;
use App\State\ExpenseDeleteProcessor;
use App\State\ExpenseUserProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A billable (or internal) expense tracked against an {@see Engagement}
 * (#650) — the sibling of {@see TimeEntry} on the cost side: members log
 * their own, invoice generation pulls the unbilled billable ones onto the
 * invoice as line items and stamps {@see $billedAt} so an expense can't be
 * billed twice. An optional {@see $receipt} MediaObject rides the gated
 * download endpoint.
 *
 * Access mirrors time entries: any space member creates (their own), the
 * author / space admin / `time_entries.update|delete` grantees edit and
 * delete, and the collection is scoped by ExpenseAccessExtension.
 */
#[ApiResource(
    shortName: 'Expense',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace() !== null and object.getSpace().hasMember(user) and is_granted('space.time_entries.create', object)))",
            processor: ExpenseUserProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.time_entries.read', object)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getUser() == user or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.time_entries.update', object)))",
            processor: ExpenseUserProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getUser() == user or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.time_entries.delete', object)))",
            processor: ExpenseDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['expense:read']],
    denormalizationContext: ['groups' => ['expense:write']],
    order: ['spentOn' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact', 'engagement' => 'exact', 'user' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['billable'])]
#[ApiFilter(DateFilter::class, properties: ['spentOn'])]
#[ApiFilter(OrderFilter::class, properties: ['spentOn', 'createdAt'])]
#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
#[ORM\Table(name: 'expense')]
#[ORM\Index(columns: ['space_id', 'spent_on'], name: 'idx_expense_space_spent')]
#[ORM\Index(columns: ['engagement_id'], name: 'idx_expense_engagement')]
#[ORM\Index(columns: ['user_id'], name: 'idx_expense_user')]
#[ORM\HasLifecycleCallbacks]
#[Assert\Callback('validateConsistency')]
class Expense
{
    public const MAX_DESCRIPTION_LENGTH = 1000;
    public const MAX_CATEGORY_LENGTH = 80;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['expense:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    #[Groups(['expense:read', 'expense:write'])]
    private ?Space $space = null;

    /** The engagement this expense bills against. Required. */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Engagement::class)]
    #[ORM\JoinColumn(name: 'engagement_id', nullable: true, onDelete: 'SET NULL')]
    #[Assert\NotNull(message: 'A engagement is required.')]
    #[Groups(['expense:read', 'expense:write'])]
    private ?Engagement $engagement = null;

    /** Who spent it. Stamped server-side, never from the payload. */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['expense:read'])]
    private ?User $user = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull(message: 'A date is required.')]
    #[Groups(['expense:read', 'expense:write'])]
    private ?\DateTimeImmutable $spentOn = null;

    /** Free-text category ("Travel", "Software", …). */
    #[ORM\Column(length: self::MAX_CATEGORY_LENGTH, nullable: true)]
    #[Assert\Length(max: self::MAX_CATEGORY_LENGTH)]
    #[Groups(['expense:read', 'expense:write'])]
    private ?string $category = null;

    /** Cost in minor units of {@see $currency}. */
    #[ORM\Column(type: 'integer')]
    #[Assert\Positive(message: 'The amount must be positive.')]
    #[Groups(['expense:read', 'expense:write'])]
    private int $amount = 0;

    /** Defaults to the engagement's (then client's) currency on save. */
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    #[Assert\Currency]
    #[Groups(['expense:read', 'expense:write'])]
    private ?string $currency = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: self::MAX_DESCRIPTION_LENGTH)]
    #[Groups(['expense:read', 'expense:write'])]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['expense:read', 'expense:write'])]
    private bool $billable = true;

    /** Receipt image/PDF — bytes stream through the gated media download. */
    #[ORM\ManyToOne(targetEntity: MediaObject::class)]
    #[ORM\JoinColumn(name: 'receipt_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['expense:read', 'expense:write'])]
    private ?MediaObject $receipt = null;

    /** Set when the expense is pulled onto an invoice; locks it against re-billing. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['expense:read'])]
    private ?\DateTimeImmutable $billedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['expense:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['expense:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Denormalise the space + currency from the engagement so the access
     * extension can scope by `space` alone and amounts always carry a currency.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function syncDerived(): void
    {
        if (null === $this->space && null !== $this->engagement) {
            $this->space = $this->engagement->getSpace();
        }
        if (null === $this->currency) {
            $this->currency = $this->engagement?->getCurrency()
                ?? $this->engagement?->getClient()?->getCurrency()
                ?? 'USD';
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function validateConsistency(ExecutionContextInterface $context): void
    {
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getSpentOn(): ?\DateTimeImmutable
    {
        return $this->spentOn;
    }

    public function setSpentOn(?\DateTimeImmutable $spentOn): self
    {
        $this->spentOn = $spentOn;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

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

    public function isBillable(): bool
    {
        return $this->billable;
    }

    public function setBillable(bool $billable): self
    {
        $this->billable = $billable;

        return $this;
    }

    public function getReceipt(): ?MediaObject
    {
        return $this->receipt;
    }

    public function setReceipt(?MediaObject $receipt): self
    {
        $this->receipt = $receipt;

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
}
