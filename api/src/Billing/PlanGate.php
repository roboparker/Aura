<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\User;
use App\Repository\SubscriptionRepository;

/**
 * Resolves the effective {@see Plan} for a billing subject and answers
 * entitlement questions through the {@see PlanCatalog} (#billing Phase 0).
 *
 * This is the seam the Organization move (Phase 1) turns on: today a space's
 * plan comes from its own subscription and a user's plan is the best across
 * the spaces they belong to; later both resolve from the org. Nothing that
 * *asks* `can()` / `limit()` has to change when that happens.
 */
final class PlanGate
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanCatalog $catalog,
    ) {
    }

    public function planForSpace(Space $space): Plan
    {
        $subscription = $this->subscriptions->findActiveForSpace($space);

        return null === $subscription ? Plan::Free : Plan::fromStorage($subscription->getPlan());
    }

    public function planForOrganization(Organization $organization): Plan
    {
        $subscription = $this->subscriptions->findActiveForOrganization($organization);

        return null === $subscription ? Plan::Free : Plan::fromStorage($subscription->getPlan());
    }

    public function organizationEntitlements(Organization $organization): PlanEntitlements
    {
        return $this->catalog->for($this->planForOrganization($organization));
    }

    /** The user's own **personal** account plan (ignores org memberships). */
    public function personalPlan(User $user): Plan
    {
        $subscription = $this->subscriptions->findActiveForUser($user);

        return null === $subscription ? Plan::Free : Plan::fromStorage($subscription->getPlan());
    }

    public function personalEntitlements(User $user): PlanEntitlements
    {
        return $this->catalog->for($this->personalPlan($user));
    }

    /** The best plan the user is entitled to across all their spaces. */
    public function planForUser(User $user): Plan
    {
        $best = Plan::Free;
        foreach ($this->subscriptions->entitlingPlansForUser($user) as $stored) {
            $best = $best->max(Plan::fromStorage($stored));
        }

        return $best;
    }

    public function spaceEntitlements(Space $space): PlanEntitlements
    {
        return $this->catalog->for($this->planForSpace($space));
    }

    public function userEntitlements(User $user): PlanEntitlements
    {
        return $this->catalog->for($this->planForUser($user));
    }

    public function spaceCan(Space $space, string $feature): bool
    {
        return $this->spaceEntitlements($space)->can($feature);
    }

    public function userCan(User $user, string $feature): bool
    {
        return $this->userEntitlements($user)->can($feature);
    }
}
