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
use App\Repository\InvoiceRepository;
use App\State\InvoiceProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An invoice a space bills to a {@see Client} (#445), the sibling of time
 * tracking: its {@see $lineItems} can be generated from unbilled billable
 * {@see TimeEntry} rows or entered by hand.
 *
 * Monetary totals ({@see $subtotal}/{@see $taxAmount}/{@see $total}, all minor
 * units of {@see $currency}) are derived server-side by InvoiceProcessor from
 * the line items and {@see $taxRate} (basis points) — never trusted from the
 * payload. The invoice pins a single currency; mixing is rejected in v1.
 *
 * Space-scoped and gated on the admin-reserved `invoices` permission category.
 * Online payment (Stripe, then PayPal) and PDF rendering ride the public
 * {@see $publicToken} in later phases.
 */
#[ApiResource(
    shortName: 'Invoice',
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_USER')",
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            securityPostDenormalize: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace() !== null and object.getSpace().hasMember(user) and is_granted('space.invoices.create', object)))",
            processor: InvoiceProcessor::class,
        ),
        new Get(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or (object.getSpace().hasMember(user) and is_granted('space.invoices.read', object)))",
        ),
        new Patch(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.invoices.update', object)))",
            processor: InvoiceProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_USER') and (is_granted('ROLE_ADMIN') or object.getSpace().isAdmin(user) or (object.getSpace().hasMember(user) and is_granted('space.invoices.delete', object)))",
        ),
    ],
    normalizationContext: ['groups' => ['invoice:read']],
    denormalizationContext: ['groups' => ['invoice:write']],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['space' => 'exact', 'client' => 'exact', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'issueDate', 'dueDate', 'number'])]
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoice')]
#[ORM\Index(columns: ['space_id', 'created_at'], name: 'idx_invoice_space_created')]
#[ORM\Index(columns: ['client_id'], name: 'idx_invoice_client')]
#[ORM\HasLifecycleCallbacks]
#[Assert\Callback('validateConsistency')]
class Invoice
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_VOID = 'void';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_PAID,
        self::STATUS_OVERDUE,
        self::STATUS_VOID,
    ];

    public const DISCOUNT_PERCENT = 'percent';
    public const DISCOUNT_FIXED = 'fixed';

    /** @var list<string> */
    public const DISCOUNT_TYPES = [self::DISCOUNT_PERCENT, self::DISCOUNT_FIXED];

    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_MONTHLY = 'monthly';
    public const FREQ_YEARLY = 'yearly';

    /** @var list<string> */
    public const FREQUENCIES = [self::FREQ_WEEKLY, self::FREQ_MONTHLY, self::FREQ_YEARLY];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['invoice:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Space is required.')]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?Space $space = null;

    #[ApiProperty(readableLink: true)]
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'A client is required.')]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?Client $client = null;

    /** Sequential per-space number, assigned when the invoice is issued (null while draft). */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['invoice:read'])]
    private ?string $number = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::STATUSES, message: 'Invalid invoice status.')]
    #[Groups(['invoice:read', 'invoice:write'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?\DateTimeImmutable $issueDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: 'string', length: 3)]
    #[Assert\NotBlank(message: 'A currency is required.')]
    #[Assert\Currency]
    #[Groups(['invoice:read', 'invoice:write'])]
    private string $currency = 'USD';

    /** Tax rate in basis points (e.g. 875 = 8.75%). */
    #[ORM\Column(type: 'integer')]
    #[Assert\Range(min: 0, max: 100000)]
    #[Groups(['invoice:read', 'invoice:write'])]
    private int $taxRate = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?string $notes = null;

    /**
     * Discount applied between subtotal and tax (#648): percent-of-subtotal or
     * a fixed amount. Null = no discount.
     */
    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Choice(choices: self::DISCOUNT_TYPES, message: 'Invalid discount type.')]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?string $discountType = null;

    /** Basis points for percent (1000 = 10%), minor units for fixed. */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?int $discountValue = null;

    /** The discount actually subtracted, minor units. Derived. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['invoice:read'])]
    private int $discountAmount = 0;

    /**
     * When set, this invoice is a recurring template: the sweep clones it into a
     * fresh draft each time {@see $nextIssueDate} arrives. One of weekly / monthly
     * / yearly, repeated every {@see $recurrenceInterval}. Recurrence runs forever
     * unless an end condition is set — at most one of {@see $recurrenceEndDate}
     * (stop once the next issue date passes it) or {@see $recurrenceCount}
     * (remaining invoices to generate; decremented on each spawn).
     */
    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Choice(choices: self::FREQUENCIES, message: 'Invalid recurrence frequency.')]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?string $recurrenceFrequency = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Positive]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?int $recurrenceInterval = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?\DateTimeImmutable $nextIssueDate = null;

    /**
     * "Until" end condition: recurrence stops once the advanced next issue date
     * would pass this date. Mutually exclusive with {@see $recurrenceCount}.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?\DateTimeImmutable $recurrenceEndDate = null;

    /**
     * "After N invoices" end condition: the number of invoices still to be
     * generated from this template. Decremented on each spawn; recurrence ends
     * when it reaches zero. Mutually exclusive with {@see $recurrenceEndDate}.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Positive]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?int $recurrenceCount = null;

    /**
     * @var Collection<int, InvoiceLineItem>
     */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoiceLineItem::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    #[Groups(['invoice:read', 'invoice:write'])]
    private Collection $lineItems;

    /**
     * Payments recorded against this invoice (#648) — server-written only
     * (payments endpoint, mark-paid, Stripe webhook), read-embedded so the
     * client sees the ledger + balance without another fetch.
     *
     * @var Collection<int, InvoicePayment>
     */
    #[ApiProperty(readableLink: true, writableLink: false)]
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoicePayment::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['paidOn' => 'ASC', 'createdAt' => 'ASC'])]
    #[Groups(['invoice:read'])]
    private Collection $payments;

    /** Sum of line amounts, minor units. Derived. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['invoice:read'])]
    private int $subtotal = 0;

    /** subtotal × taxRate, minor units. Derived. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['invoice:read'])]
    private int $taxAmount = 0;

    /** subtotal + taxAmount, minor units. Derived. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['invoice:read'])]
    private int $total = 0;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['invoice:read'])]
    private ?User $createdBy = null;

    /** sha256 of the public-view/pay token; the plaintext only ever leaves in email/links. */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $publicToken = null;

    /**
     * Per-invoice opt-out for the automatic late-payment reminder emails
     * (#649) — on by default; a space admin can pause them per invoice.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['invoice:read', 'invoice:write'])]
    private bool $remindersEnabled = true;

    /**
     * ISO-8601 timestamps of each late-payment reminder actually emailed —
     * the idempotency log the daily sweep checks before sending the next one
     * (max 3: on overdue, +7d, +14d).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['invoice:read'])]
    private ?array $remindersSentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['invoice:read'])]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['invoice:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['invoice:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['invoice:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lineItems = new ArrayCollection();
        $this->payments = new ArrayCollection();
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

        if (null !== $this->discountType && null === $this->discountValue) {
            $context->buildViolation('A discount needs a value.')
                ->atPath('discountValue')
                ->addViolation();
        }
        if (null !== $this->discountValue) {
            if (null === $this->discountType) {
                $context->buildViolation('A discount value needs a type (percent or fixed).')
                    ->atPath('discountType')
                    ->addViolation();
            } elseif ($this->discountValue < 0) {
                $context->buildViolation('A discount cannot be negative.')
                    ->atPath('discountValue')
                    ->addViolation();
            } elseif (self::DISCOUNT_PERCENT === $this->discountType && $this->discountValue > 10000) {
                $context->buildViolation('A percent discount cannot exceed 100%.')
                    ->atPath('discountValue')
                    ->addViolation();
            }
        }

        if (null !== $this->recurrenceFrequency) {
            if (null === $this->recurrenceInterval || $this->recurrenceInterval < 1) {
                $context->buildViolation('A recurring invoice needs an interval of at least 1.')
                    ->atPath('recurrenceInterval')
                    ->addViolation();
            }
            if (null === $this->nextIssueDate) {
                $context->buildViolation('A recurring invoice needs a next issue date.')
                    ->atPath('nextIssueDate')
                    ->addViolation();
            }
            if (null !== $this->recurrenceEndDate && null !== $this->recurrenceCount) {
                $context->buildViolation('Choose one end condition: an end date or a number of invoices, not both.')
                    ->atPath('recurrenceEndDate')
                    ->addViolation();
            }
            if (
                null !== $this->recurrenceEndDate && null !== $this->nextIssueDate
                && $this->recurrenceEndDate < $this->nextIssueDate
            ) {
                $context->buildViolation('The recurrence end date must be on or after the next issue date.')
                    ->atPath('recurrenceEndDate')
                    ->addViolation();
            }
        } elseif (null !== $this->recurrenceEndDate || null !== $this->recurrenceCount) {
            // End conditions are meaningless without a recurrence — reject rather
            // than silently store dangling values.
            $context->buildViolation('An end condition requires a recurrence frequency.')
                ->atPath('recurrenceFrequency')
                ->addViolation();
        }
    }

    public function isRecurring(): bool
    {
        return null !== $this->recurrenceFrequency;
    }

    /** The interval string for DateTimeImmutable::modify(), e.g. "+1 month". */
    public function recurrenceStep(): ?string
    {
        if (null === $this->recurrenceFrequency || null === $this->recurrenceInterval) {
            return null;
        }
        $unit = match ($this->recurrenceFrequency) {
            self::FREQ_WEEKLY => 'week',
            self::FREQ_MONTHLY => 'month',
            self::FREQ_YEARLY => 'year',
            default => null,
        };

        return null === $unit ? null : sprintf('+%d %s', $this->recurrenceInterval, $unit);
    }

    public function getRecurrenceFrequency(): ?string
    {
        return $this->recurrenceFrequency;
    }

    public function setRecurrenceFrequency(?string $recurrenceFrequency): self
    {
        $this->recurrenceFrequency = $recurrenceFrequency;

        return $this;
    }

    public function getRecurrenceInterval(): ?int
    {
        return $this->recurrenceInterval;
    }

    public function setRecurrenceInterval(?int $recurrenceInterval): self
    {
        $this->recurrenceInterval = $recurrenceInterval;

        return $this;
    }

    public function getNextIssueDate(): ?\DateTimeImmutable
    {
        return $this->nextIssueDate;
    }

    public function setNextIssueDate(?\DateTimeImmutable $nextIssueDate): self
    {
        $this->nextIssueDate = $nextIssueDate;

        return $this;
    }

    public function getRecurrenceEndDate(): ?\DateTimeImmutable
    {
        return $this->recurrenceEndDate;
    }

    public function setRecurrenceEndDate(?\DateTimeImmutable $recurrenceEndDate): self
    {
        $this->recurrenceEndDate = $recurrenceEndDate;

        return $this;
    }

    public function getRecurrenceCount(): ?int
    {
        return $this->recurrenceCount;
    }

    public function setRecurrenceCount(?int $recurrenceCount): self
    {
        $this->recurrenceCount = $recurrenceCount;

        return $this;
    }

    /**
     * Stops recurrence: clears the frequency/interval/next-issue-date and both
     * end conditions so the template drops out of the due-recurring sweep and
     * reads as a plain (non-recurring) invoice.
     */
    public function endRecurrence(): self
    {
        $this->recurrenceFrequency = null;
        $this->recurrenceInterval = null;
        $this->nextIssueDate = null;
        $this->recurrenceEndDate = null;
        $this->recurrenceCount = null;

        return $this;
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

    public function getIssueDate(): ?\DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function setIssueDate(?\DateTimeImmutable $issueDate): self
    {
        $this->issueDate = $issueDate;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): self
    {
        $this->dueDate = $dueDate;

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
     * @return Collection<int, InvoiceLineItem>
     */
    public function getLineItems(): Collection
    {
        return $this->lineItems;
    }

    public function addLineItem(InvoiceLineItem $lineItem): self
    {
        if (!$this->lineItems->contains($lineItem)) {
            $this->lineItems->add($lineItem);
            $lineItem->setInvoice($this);
        }

        return $this;
    }

    public function removeLineItem(InvoiceLineItem $lineItem): self
    {
        if ($this->lineItems->removeElement($lineItem) && $lineItem->getInvoice() === $this) {
            $lineItem->setInvoice(null);
        }

        return $this;
    }

    public function getDiscountType(): ?string
    {
        return $this->discountType;
    }

    public function setDiscountType(?string $discountType): self
    {
        $this->discountType = $discountType;

        return $this;
    }

    public function getDiscountValue(): ?int
    {
        return $this->discountValue;
    }

    public function setDiscountValue(?int $discountValue): self
    {
        $this->discountValue = $discountValue;

        return $this;
    }

    public function getDiscountAmount(): int
    {
        return $this->discountAmount;
    }

    public function setDiscountAmount(int $discountAmount): self
    {
        $this->discountAmount = $discountAmount;

        return $this;
    }

    /**
     * @return Collection<int, InvoicePayment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    /**
     * Mutates the ledger — marked impure so PHPStan forgets remembered
     * getBalanceDue()/getStatus() values after a call (the fluent return
     * otherwise makes it look side-effect-free).
     *
     * @phpstan-impure
     */
    public function addPayment(InvoicePayment $payment): self
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setInvoice($this);
        }

        return $this;
    }

    /** @phpstan-impure */
    public function removePayment(InvoicePayment $payment): self
    {
        if ($this->payments->removeElement($payment) && $payment->getInvoice() === $this) {
            $payment->setInvoice(null);
        }

        return $this;
    }

    /** Σ recorded payments, minor units. */
    #[Groups(['invoice:read'])]
    public function getAmountPaid(): int
    {
        $sum = 0;
        foreach ($this->payments as $payment) {
            $sum += $payment->getAmount();
        }

        return $sum;
    }

    /** total − Σ payments, floored at zero. */
    #[Groups(['invoice:read'])]
    public function getBalanceDue(): int
    {
        return max(0, $this->total - $this->getAmountPaid());
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

    /**
     * Tax grouped by effective rate (#670) — one row per distinct nonzero
     * rate, mirroring InvoiceProcessor's per-line proportional math so the
     * rows always sum to {@see $taxAmount}. Lets the PDF/public page print
     * "Tax (10%)" and "Tax (21%)" separately on mixed-rate invoices.
     *
     * @return list<array{rate: int, amount: int}>
     */
    #[Groups(['invoice:read'])]
    public function getTaxBreakdown(): array
    {
        $taxable = $this->subtotal - $this->discountAmount;
        $ratio = $this->subtotal > 0 ? $taxable / $this->subtotal : 0.0;

        $byRate = [];
        foreach ($this->lineItems as $line) {
            $rate = $line->getTaxRate() ?? $this->taxRate;
            if ($rate <= 0) {
                continue;
            }
            $byRate[$rate] = ($byRate[$rate] ?? 0)
                + (int) round($line->getAmount() * $ratio * $rate / 10000);
        }
        ksort($byRate);

        $rows = [];
        foreach ($byRate as $rate => $amount) {
            $rows[] = ['rate' => $rate, 'amount' => $amount];
        }

        return $rows;
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

    public function isRemindersEnabled(): bool
    {
        return $this->remindersEnabled;
    }

    public function setRemindersEnabled(bool $remindersEnabled): self
    {
        $this->remindersEnabled = $remindersEnabled;

        return $this;
    }

    /** @return list<string> */
    public function getRemindersSentAt(): array
    {
        return $this->remindersSentAt ?? [];
    }

    public function addReminderSentAt(\DateTimeImmutable $when): self
    {
        $log = $this->getRemindersSentAt();
        $log[] = $when->format(\DateTimeInterface::ATOM);
        $this->remindersSentAt = $log;

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

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;

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
