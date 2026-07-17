<?php

namespace App\Entity;

use App\Repository\RetainerTransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One movement on a client's retainer ledger (#673): a `deposit` (money the
 * client pre-paid, recorded by an admin) or a `draw` (retainer balance
 * applied to an invoice — paired with an InvoicePayment of method
 * "retainer"). Balance = Σ deposits − Σ draws, in the client's currency.
 *
 * Server-written only (RetainerController + the payment-removal reversal);
 * not an API resource. Rows are never edited — corrections are new
 * counter-entries, so the ledger stays an audit trail.
 */
#[ORM\Entity(repositoryClass: RetainerTransactionRepository::class)]
#[ORM\Table(name: 'retainer_transaction')]
#[ORM\Index(columns: ['client_id', 'created_at'], name: 'idx_retainer_txn_client_created')]
class RetainerTransaction
{
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_DRAW = 'draw';

    public const MAX_NOTE_LENGTH = 255;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Client $client = null;

    #[ORM\Column(length: 10)]
    private string $type = self::TYPE_DEPOSIT;

    /** Minor units of the client's currency; always positive. */
    #[ORM\Column(type: 'integer')]
    private int $amount = 0;

    #[ORM\Column(length: self::MAX_NOTE_LENGTH, nullable: true)]
    private ?string $note = null;

    /** The invoice a draw paid down (null for deposits). */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

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

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
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
