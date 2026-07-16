<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line on an {@see Invoice} (#445). Written as part of the invoice's
 * `lineItems` array (like Task custom-field values) rather than through its own
 * endpoint. {@see $amount} (quantity × unit price, minor units) is derived
 * server-side by InvoiceProcessor, never trusted from the payload.
 *
 * {@see $sourceTimeEntry} back-links a line generated from tracked time so the
 * two stay traceable and the entry can be un-billed if the invoice is voided.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_line_item')]
#[ORM\Index(columns: ['invoice_id', 'position'], name: 'idx_invoice_line_item_invoice_position')]
class InvoiceLineItem
{
    public const MAX_DESCRIPTION_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['invoice:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lineItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Invoice $invoice = null;

    #[ORM\Column(length: self::MAX_DESCRIPTION_LENGTH)]
    #[Assert\NotBlank(message: 'A line description is required.')]
    #[Assert\Length(max: self::MAX_DESCRIPTION_LENGTH)]
    #[Groups(['invoice:read', 'invoice:write'])]
    private string $description = '';

    /** Hours or units (may be fractional). */
    #[ORM\Column(type: 'float')]
    #[Assert\PositiveOrZero]
    #[Groups(['invoice:read', 'invoice:write'])]
    private float $quantity = 1.0;

    /** Price per unit/hour in minor units of the invoice's currency. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['invoice:read', 'invoice:write'])]
    private int $unitAmount = 0;

    /** Derived: round(quantity × unitAmount), in minor units. Read-only. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['invoice:read'])]
    private int $amount = 0;

    #[ORM\Column(type: 'integer')]
    #[Groups(['invoice:read', 'invoice:write'])]
    private int $position = 0;

    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: TimeEntry::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['invoice:read', 'invoice:write'])]
    private ?TimeEntry $sourceTimeEntry = null;

    /** Back-link for a line generated from an expense (#650) — lets void release it. */
    #[ApiProperty(readableLink: false)]
    #[ORM\ManyToOne(targetEntity: Expense::class)]
    #[ORM\JoinColumn(name: 'source_expense_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['invoice:read'])]
    private ?Expense $sourceExpense = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function setQuantity(float $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitAmount(): int
    {
        return $this->unitAmount;
    }

    public function setUnitAmount(int $unitAmount): self
    {
        $this->unitAmount = $unitAmount;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getSourceTimeEntry(): ?TimeEntry
    {
        return $this->sourceTimeEntry;
    }

    public function setSourceTimeEntry(?TimeEntry $sourceTimeEntry): self
    {
        $this->sourceTimeEntry = $sourceTimeEntry;

        return $this;
    }

    public function getSourceExpense(): ?Expense
    {
        return $this->sourceExpense;
    }

    public function setSourceExpense(?Expense $sourceExpense): self
    {
        $this->sourceExpense = $sourceExpense;

        return $this;
    }
}
