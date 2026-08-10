<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One AI charge against an organization's monthly allowance (#827).
 *
 * **Not an API resource and never written through the ORM** — the schema is
 * declared here so `doctrine:schema:validate` and the migrations stay in step,
 * but every read and write goes through {@see \App\Service\AiCreditMeter} over
 * DBAL. That is the same split {@see UserUsageCounter} and
 * {@see \App\Service\UsageRecorder} already use, and here it is load-bearing:
 * reserving requires a row lock and a conditional insert in one transaction,
 * which is not something the UnitOfWork expresses.
 *
 * ## Why a ledger rather than a counter
 *
 * The issue's rule is *reserve before the call, reconcile after*, so a
 * mid-flight failure cannot overspend. A single running total cannot express an
 * in-flight amount that might yet be released, so each charge is its own row
 * with a lifecycle:
 *
 *  - `pending` — reserved before the provider call, holding the **most** the
 *    call could cost. Counts against the balance immediately, so two
 *    concurrent requests cannot each spend the last of the allowance.
 *  - `settled` — reconciled to the provider's reported token usage once the
 *    call returned. Almost always *less* than was reserved.
 *
 * A released reservation is deleted outright: a failed call is not a charge,
 * and keeping a zero row would just be noise in a table people will read to
 * understand a bill.
 *
 * ## Why `expiresAt` rather than a sweeper
 *
 * If the process dies between reserving and settling, the pending row is
 * orphaned and would hold credits hostage forever. Rather than depend on a cron
 * to notice, the balance query ignores pending rows past `expiresAt`, so a
 * leak heals itself after the TTL with no moving parts. The sweep that deletes
 * them is then pure housekeeping and can never be the difference between a
 * customer being able to use the product or not.
 *
 * ## Tokens, not credits
 *
 * Amounts are stored in **tokens**, the unit providers actually bill in and the
 * unit the issue says to charge on. Credits are a presentation layer over them
 * (`app.ai_tokens_per_credit`), applied only at the plan boundary and in the
 * UI, so rounding never compounds across a month of charges.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_credit_ledger')]
#[ORM\Index(columns: ['organization_id', 'period'], name: 'idx_ai_credit_ledger_org_period')]
#[ORM\Index(columns: ['state', 'expires_at'], name: 'idx_ai_credit_ledger_expiry')]
class AiCreditLedgerEntry
{
    /** Reserved before the call; may still be released. */
    public const STATE_PENDING = 'pending';

    /** Reconciled to what the provider reported. Final. */
    public const STATE_SETTLED = 'settled';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /**
     * The account the charge lands on. Credits pool at the organization, not
     * the space or the agent: an org buys one allowance and its spaces share
     * it, which is also how the plan that grants them is sold.
     */
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    /**
     * `YYYY-MM` in UTC. Stored rather than derived from `createdAt` so the
     * window a charge belongs to is fixed at write time and can never be moved
     * by a later timezone or query change.
     */
    #[ORM\Column(length: 7)]
    private string $period = '';

    #[ORM\Column(length: 16)]
    private string $state = self::STATE_PENDING;

    /** Reserved estimate while pending; actual total once settled. */
    #[ORM\Column(type: 'integer')]
    private int $tokens = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $promptTokens = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $completionTokens = null;

    #[ORM\Column(length: 32)]
    private string $provider = '';

    #[ORM\Column(length: 64)]
    private string $model = '';

    /**
     * Which agent spent it, for attribution. SET NULL rather than CASCADE:
     * removing an agent must not erase the record of what it cost, or a month's
     * spend could be made to disappear by deleting the thing that caused it.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $agent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $settledAt = null;

    /** After this, a still-pending row stops counting against the balance. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;
        return $this;
    }

    public function getPeriod(): string
    {
        return $this->period;
    }

    public function setPeriod(string $period): static
    {
        $this->period = $period;
        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): static
    {
        $this->state = $state;
        return $this;
    }

    public function getTokens(): int
    {
        return $this->tokens;
    }

    public function setTokens(int $tokens): static
    {
        $this->tokens = $tokens;
        return $this;
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function setPromptTokens(?int $promptTokens): static
    {
        $this->promptTokens = $promptTokens;
        return $this;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function setCompletionTokens(?int $completionTokens): static
    {
        $this->completionTokens = $completionTokens;
        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;
        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): static
    {
        $this->model = $model;
        return $this;
    }

    public function getAgent(): ?User
    {
        return $this->agent;
    }

    public function setAgent(?User $agent): static
    {
        $this->agent = $agent;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getSettledAt(): ?\DateTimeImmutable
    {
        return $this->settledAt;
    }

    public function setSettledAt(?\DateTimeImmutable $settledAt): static
    {
        $this->settledAt = $settledAt;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }
}
