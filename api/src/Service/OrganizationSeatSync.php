<?php

declare(strict_types=1);

namespace App\Service;

use App\Billing\BillingException;
use App\Entity\Organization;
use App\Message\SyncOrganizationSeats;
use App\Repository\SubscriptionRepository;
use App\Billing\StripeGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps an organization's Stripe seat quantity equal to its billable member
 * count (#billing Phase 1c).
 *
 * Seats are what a per-seat plan charges for, so every membership change is a
 * billing event. Without this, adding the tenth member to a five-seat
 * subscription costs nothing — the gap between what an org uses and what it
 * pays is a straight revenue leak, and it only widens.
 *
 * Two halves, deliberately split:
 *
 *  - {@see schedule()} is what call sites use. It just enqueues, so a member
 *    change never waits on (or fails because of) a Stripe round-trip. Sizing
 *    the seat count is our business; billing for it is Stripe's, and the two
 *    shouldn't share a failure domain.
 *  - {@see sync()} runs on the worker and does the push.
 *
 * A push failure is logged, not raised: Messenger retries it, and a seat count
 * that lags Stripe by a few minutes is a far better outcome than a member add
 * that 500s because Stripe was briefly unreachable. The org's own membership
 * rows stay the source of truth either way.
 */
final class OrganizationSeatSync
{
    public function __construct(
        private EntityManagerInterface $em,
        private SubscriptionRepository $subscriptions,
        private StripeGatewayInterface $stripe,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Queue a seat push for this org. Safe to call on every membership change —
     * unconfigured instances and orgs with no subscription drop out on the
     * worker side, so callers don't need to know anything about billing.
     */
    public function schedule(Organization $organization): void
    {
        $id = $organization->getId();
        if (null === $id) {
            return;
        }
        $this->bus->dispatch(new SyncOrganizationSeats((string) $id));
    }

    /**
     * Queue only when the role occupies a seat. For the auto-join path, where
     * the caller has the resolved role but not a reason to care about the
     * billing rule.
     *
     * **Call after flushing.** {@see sync()} re-reads the roster from the
     * database, so dispatching before the membership is persisted would push
     * the pre-change count — and under `sync://` in the test environment the
     * handler runs inside the dispatch, which makes that a certainty rather
     * than a race.
     */
    public function scheduleIfBillable(?Organization $organization, ?string $role): void
    {
        if (null === $organization || null === $role || Organization::ROLE_GUEST === $role) {
            return;
        }
        $this->schedule($organization);
    }

    /**
     * Push the org's current seat count to Stripe. Returns the quantity sent,
     * or null when there was nothing to do (no such org, billing not wired, no
     * per-seat subscription, or the org is mid-deletion).
     */
    public function sync(string $organizationId): ?int
    {
        if (!$this->stripe->isConfigured() || !Uuid::isValid($organizationId)) {
            return null;
        }

        $organization = $this->em->getRepository(Organization::class)->find($organizationId);
        if (null === $organization) {
            return null;
        }
        // A deleted org's subscription is cancelled by the deletion flow;
        // re-sizing it here would be a pointless write against a subscription
        // on its way out, and could resurrect a proration after cancellation.
        if ($organization->isDeleted()) {
            return null;
        }

        $subscription = $this->subscriptions->findActiveForOrganization($organization);
        $stripeId = $subscription?->getStripeSubscriptionId();
        if (null === $subscription || null === $stripeId || '' === $stripeId) {
            return null;
        }

        // Stripe rejects a zero quantity, and an org always has at least one
        // owner anyway — the clamp is a guard against an inconsistent roster,
        // not an expected path.
        $quantity = max(1, $organization->seatCount());

        try {
            $this->stripe->updateSubscriptionQuantity($stripeId, $quantity);
        } catch (BillingException $e) {
            $this->logger->error('Failed to sync seat quantity for organization {org}: {error}', [
                'org' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Mirror locally so the billing card reflects the push without waiting
        // for the customer.subscription.updated webhook to land.
        $subscription->setSeats($quantity)->touch();
        $this->em->flush();

        return $quantity;
    }
}
