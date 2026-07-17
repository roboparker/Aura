<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * One payment recorded against an {@see Invoice} (#648) — Harvest-style
 * partial payments: the invoice's balance due = total − Σ payments, and the
 * status only flips to paid when the balance hits zero. Rows are written
 * through `POST /invoices/{id}/payments` (manual entry), the mark-paid action
 * (records the remaining balance), and the Stripe webhook (online payment) —
 * never directly by clients, so amounts are always server-controlled.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_payment')]
#[ORM\Index(columns: ['invoice_id'], name: 'idx_invoice_payment_invoice')]
class InvoicePayment
{
    public const METHOD_MANUAL = 'manual';
    public const METHOD_STRIPE = 'stripe';
    /** Paid from the client's retainer balance (#673). */
    public const METHOD_RETAINER = 'retainer';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['invoice:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Invoice $invoice = null;

    /** Minor units of the invoice's currency. Always positive. */
    #[ORM\Column(type: 'integer')]
    #[Groups(['invoice:read'])]
    private int $amount = 0;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['invoice:read'])]
    private \DateTimeImmutable $paidOn;

    /** e.g. "manual", "stripe", "bank transfer", "check". */
    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['invoice:read'])]
    private ?string $method = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['invoice:read'])]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['invoice:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->paidOn = new \DateTimeImmutable('today');
    }

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

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getPaidOn(): \DateTimeImmutable
    {
        return $this->paidOn;
    }

    public function setPaidOn(\DateTimeImmutable $paidOn): self
    {
        $this->paidOn = $paidOn;

        return $this;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(?string $method): self
    {
        $this->method = $method;

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
}
