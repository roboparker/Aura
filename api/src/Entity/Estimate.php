<?php

namespace App\Entity;

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
use App\Repository\EstimateRepository;
use App\State\EstimateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An estimate/quote a space sends to a {@see Client} (#652) — the Invoice's
 * pre-sales sibling: same line-item + derived-totals model, but its lifecycle
 * is draft → sent → accepted/declined, decided by the client from the public
 * token page. An accepted estimate converts into a draft {@see Invoice}
 * (copied lines, {@see $convertedInvoice} back-link) so the quote and the
 * bill stay traceable.
 *
 * Totals are derived server-side by EstimateProcessor (never trusted from
 * the payload). Space-scoped and gated on the admin-reserved `invoices`
 * permission category like Invoice/Client.
 */
#[ApiResource(
    shortName: 'Estimate',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace() !== null and object.getSpace().hasMember(user) and is_granted('space.invoices.create', object)))",
            processor: EstimateProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.invoices.read', object)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.invoices.update', object)))",
            processor: EstimateProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.invoices.delete', object)))",
        ),
    ],
    normalizationContext: ['groups' => ['estimate:read']],
    denormalizationContext: ['groups' => ['estimate:write']],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact', 'client' => 'exact', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'number'])]
#[ORM\Entity(repositoryClass: EstimateRepository::class)]
#[ORM\Table(name: 'estimate')]
#[ORM\Index(columns: ['space_id', 'created_at'], name: 'idx_estimate_space_created')]
#[ORM\Index(columns: ['client_id'], name: 'idx_estimate_client')]
#[ORM\HasLifecycleCallbacks]
#[Assert\Callback('validateConsistency')]
class Estimate
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['estimate:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    #[Groups(['estimate:read', 'estimate:write'])]
    private ?Space $space = null;

    #[ApiProperty(readableLink: true)]
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'A client is required.')]
    #[Groups(['estimate:read', 'estimate:write'])]
    private ?Client $client = null;

    /** Sequential per-space number, assigned on send (null while draft). */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['estimate:read'])]
    private ?string $number = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::STATUSES, message: 'Invalid estimate status.')]
    #[Groups(['estimate:read', 'estimate:write'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: 'string', length: 3)]
    #[Assert\NotBlank(message: 'A currency is required.')]
    #[Assert\Currency]
    #[Groups(['estimate:read', 'estimate:write'])]
    private string $currency = 'USD';

    /** Tax rate in basis points (e.g. 875 = 8.75%). */
    #[ORM\Column(type: 'integer')]
    #[Assert\Range(min: 0, max: 100000)]
    #[Groups(['estimate:read', 'estimate:write'])]
    private int $taxRate = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['estimate:read', 'estimate:write'])]
    private ?string $notes = null;

    /**
     * @var Collection<int, EstimateLineItem>
     */
    #[ORM\OneToMany(mappedBy: 'estimate', targetEntity: EstimateLineItem::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    #[Groups(['estimate:read', 'estimate:write'])]
    private Collection $lineItems;

    /** Sum of line amounts, minor units. Derived. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['estimate:read'])]
    private int $subtotal = 0;

    /** subtotal × taxRate, minor units. Derived. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['estimate:read'])]
    private int $taxAmount = 0;

    /** subtotal + taxAmount, minor units. Derived. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['estimate:read'])]
    private int $total = 0;

    /** The invoice this estimate was converted into (#652). */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'converted_invoice_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['estimate:read'])]
    private ?Invoice $convertedInvoice = null;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['estimate:read'])]
    private ?User $createdBy = null;

    /** sha256 of the public accept/decline token; plaintext only leaves in the link. */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $publicToken = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['estimate:read'])]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['estimate:read'])]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['estimate:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['estimate:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lineItems = new ArrayCollection();
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

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(?string $number): self
    {
        $this->number = $number;

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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getTaxRate(): int
    {
        return $this->taxRate;
    }

    public function setTaxRate(int $taxRate): self
    {
        $this->taxRate = $taxRate;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * @return Collection<int, EstimateLineItem>
     */
    public function getLineItems(): Collection
    {
        return $this->lineItems;
    }

    public function addLineItem(EstimateLineItem $lineItem): self
    {
        if (!$this->lineItems->contains($lineItem)) {
            $this->lineItems->add($lineItem);
            $lineItem->setEstimate($this);
        }

        return $this;
    }

    public function removeLineItem(EstimateLineItem $lineItem): self
    {
        if ($this->lineItems->removeElement($lineItem) && $lineItem->getEstimate() === $this) {
            $lineItem->setEstimate(null);
        }

        return $this;
    }

    public function getSubtotal(): int
    {
        return $this->subtotal;
    }

    public function setSubtotal(int $subtotal): self
    {
        $this->subtotal = $subtotal;

        return $this;
    }

    public function getTaxAmount(): int
    {
        return $this->taxAmount;
    }

    public function setTaxAmount(int $taxAmount): self
    {
        $this->taxAmount = $taxAmount;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): self
    {
        $this->total = $total;

        return $this;
    }

    public function getConvertedInvoice(): ?Invoice
    {
        return $this->convertedInvoice;
    }

    public function setConvertedInvoice(?Invoice $convertedInvoice): self
    {
        $this->convertedInvoice = $convertedInvoice;

        return $this;
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

    public function getPublicToken(): ?string
    {
        return $this->publicToken;
    }

    public function setPublicToken(?string $publicToken): self
    {
        $this->publicToken = $publicToken;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
