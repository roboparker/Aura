<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A paid "Team" subscription attached to a {@see Space} (the billable unit).
 *
 * The freemium gate: a free shared space is capped (members + MCP throughput
 * for its members); an active subscription lifts those caps. The row mirrors
 * the authoritative state in Stripe — it is created/updated only by the
 * billing webhook handler ({@see \App\Controller\BillingController}) in
 * response to Stripe events, never written through the API. Personal spaces
 * are never billable, so they never carry a row.
 *
 * `status` tracks the Stripe subscription lifecycle; {@see ENTITLING_STATUSES}
 * is the subset that actually unlocks paid features. A space may accumulate
 * more than one row over its lifetime (a canceled sub plus a fresh one), so
 * lookups always filter by entitling status and take the newest — see
 * {@see SubscriptionRepository::findActiveForSpace()}.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscription')]
#[ORM\Index(columns: ['space_id'], name: 'idx_subscription_space')]
#[ORM\Index(columns: ['organization_id'], name: 'idx_subscription_organization')]
#[ORM\Index(columns: ['owner_user_id'], name: 'idx_subscription_owner_user')]
#[ORM\Index(columns: ['created_by_id'], name: 'idx_subscription_created_by')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_stripe_id', columns: ['stripe_subscription_id'])]
class Subscription
{
    public const PLAN_TEAM = 'team';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_INCOMPLETE_EXPIRED = 'incomplete_expired';
    public const STATUS_UNPAID = 'unpaid';

    public const INTERVAL_MONTH = 'month';
    public const INTERVAL_YEAR = 'year';

    /**
     * Statuses that grant paid entitlement. `past_due` is intentionally
     * included so a temporary payment hiccup doesn't instantly lock a team
     * out of their own data — Stripe's dunning retries the charge, and only
     * a terminal `canceled` / `unpaid` revokes access.
     *
     * @var list<string>
     */
    public const ENTITLING_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_TRIALING,
        self::STATUS_PAST_DUE,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /**
     * The **account** this subscription pays for (#billing Phase 1b): either an
     * {@see Organization} (a team account) or a {@see User} (a personal
     * account) — exactly one is set for new rows. Legacy rows also carry the
     * originating {@see $space} (now nullable + transitional); resolution goes
     * account-first, falling back to the space.
     */
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', nullable: true, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    /** The personal account (a User) this subscription pays for, when personal. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_user_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $ownerUser = null;

    /**
     * Legacy: the space this subscription originally paid for (transitional;
     * superseded by {@see $organization}). Nullable now; a later cleanup drops
     * it once nothing reads it.
     */
    #[ORM\ManyToOne(targetEntity: Space::class)]
    #[ORM\JoinColumn(name: 'space_id', nullable: true, onDelete: 'CASCADE')]
    private ?Space $space = null;

    #[ORM\Column(length: 32, options: ['default' => self::PLAN_TEAM])]
    private string $plan = self::PLAN_TEAM;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_INCOMPLETE;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $billingInterval = null;

    /**
     * Purchased seat quantity (Stripe subscription item quantity). The free
     * member cap is lifted entirely while a subscription is active, so this
     * is informational/audit today; it becomes the enforced ceiling if we
     * ever meter seats exactly.
     */
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $seats = 1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePriceId = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $cancelAtPeriodEnd = false;

    /**
     * Who initiated the subscription (audit only). Nullable + SET NULL so
     * user deletion doesn't cascade into the billing record.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getOwnerUser(): ?User
    {
        return $this->ownerUser;
    }

    public function setOwnerUser(?User $ownerUser): static
    {
        $this->ownerUser = $ownerUser;
        return $this;
    }

    public function getSpace(): ?Space
    {
        return $this->space;
    }

    public function setSpace(?Space $space): static
    {
        $this->space = $space;
        return $this;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function setPlan(string $plan): static
    {
        $this->plan = $plan;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * True when this row currently grants paid entitlement.
     */
    public function isEntitling(): bool
    {
        return in_array($this->status, self::ENTITLING_STATUSES, true);
    }

    public function getBillingInterval(): ?string
    {
        return $this->billingInterval;
    }

    public function setBillingInterval(?string $billingInterval): static
    {
        $this->billingInterval = $billingInterval;
        return $this;
    }

    public function getSeats(): int
    {
        return $this->seats;
    }

    public function setSeats(int $seats): static
    {
        $this->seats = $seats;
        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;
        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        return $this;
    }

    public function getStripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function setStripePriceId(?string $stripePriceId): static
    {
        $this->stripePriceId = $stripePriceId;
        return $this;
    }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(?\DateTimeImmutable $currentPeriodEnd): static
    {
        $this->currentPeriodEnd = $currentPeriodEnd;
        return $this;
    }

    public function getCancelAtPeriodEnd(): bool
    {
        return $this->cancelAtPeriodEnd;
    }

    public function setCancelAtPeriodEnd(bool $cancelAtPeriodEnd): static
    {
        $this->cancelAtPeriodEnd = $cancelAtPeriodEnd;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Stamp the update timestamp. Called by the webhook handler whenever it
     * syncs Stripe state onto the row (this entity is never touched through
     * the API, so there's no Gedmo Timestampable hook driving it).
     */
    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
