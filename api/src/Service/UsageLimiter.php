<?php

declare(strict_types=1);

namespace App\Service;

use App\Billing\PlanGate;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Connection;

/**
 * The freemium gate's read side: decides whether a given action is within a
 * user's / space's free allowance, or requires (or is unlocked by) a paid
 * subscription.
 *
 * Two levers, mirroring the hybrid plan:
 *  - **MCP/API throughput** — a per-user daily cap on automation calls,
 *    lifted to unlimited once the user belongs to any entitled space.
 *  - **Space members** — a per-space ceiling on a free shared space, lifted
 *    once the space itself is subscribed.
 *
 * Counting itself stays where it already lives ({@see UsageRecorder} +
 * {@see \App\Entity\UserUsageCounter}); this service only *reads* those
 * counters to make a yes/no decision. The headline metric is `mcp_call` —
 * the PWA's own cookie-authenticated REST traffic is never metered, only
 * programmatic MCP/token usage.
 *
 * Every decision short-circuits to "allowed" while {@see $enforcementEnabled}
 * is false, so the gate can ship dark and be switched on once Stripe is live.
 */
final class UsageLimiter
{
    public function __construct(
        private Connection $connection,
        private SubscriptionRepository $subscriptions,
        private PlanGate $planGate,
        private int $freeMcpDailyLimit,
        private int $freeSpaceMemberLimit,
        private bool $enforcementEnabled,
    ) {
    }

    public function isEnforcementEnabled(): bool
    {
        return $this->enforcementEnabled;
    }

    public function freeMcpDailyLimit(): int
    {
        return $this->freeMcpDailyLimit;
    }

    public function freeSpaceMemberLimit(): int
    {
        return $this->freeSpaceMemberLimit;
    }

    /**
     * True if the user has unlimited automation throughput (belongs to an
     * entitled space). Cheap EXISTS-style count; safe to call per request.
     */
    public function isUserEntitled(User $user): bool
    {
        return $this->subscriptions->userHasActiveEntitlement($user);
    }

    /**
     * True for platform admins. Their usage is still counted (so we can see
     * what they consume), but they are exempt from the free MCP throughput cap
     * — operating the instance shouldn't be rate-limited by the freemium gate.
     */
    public function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    /**
     * The user's daily MCP allowance from their best plan (null = unlimited).
     * Platform admins are always unlimited.
     */
    private function mcpLimit(User $user): ?int
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        return $this->planGate->userEntitlements($user)->limit('mcp_calls_per_day');
    }

    /**
     * Today's (UTC) count of metered MCP tool calls for this user.
     */
    public function mcpCallsToday(User $user): int
    {
        $userId = $user->getId();
        if (null === $userId) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT count FROM user_usage_counter
                WHERE user_id = :userId AND day = :day AND metric = :metric
                SQL,
            [
                'userId' => $userId->toRfc4122(),
                'day' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'),
                'metric' => UsageRecorder::METRIC_MCP_CALL,
            ],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Remaining free MCP calls for today, or null when unlimited (entitled
     * or enforcement disabled). Floored at zero.
     */
    public function remainingMcpCalls(User $user): ?int
    {
        if (!$this->enforcementEnabled) {
            return null;
        }
        $limit = $this->mcpLimit($user);
        if (null === $limit) {
            return null;
        }

        return max(0, $limit - $this->mcpCallsToday($user));
    }

    /**
     * Whether the user may make one more MCP call right now. Entitled users
     * and (while the gate is dark) everyone are always allowed.
     */
    public function isMcpCallAllowed(User $user): bool
    {
        if (!$this->enforcementEnabled) {
            return true;
        }
        $limit = $this->mcpLimit($user);
        if (null === $limit) {
            return true;
        }

        return $this->mcpCallsToday($user) < $limit;
    }

    /**
     * Whether {@see $count} more member(s) can be added to a space under the
     * free ceiling. Personal spaces (always solo) and subscribed spaces are
     * unbounded. Counts the current effective members (direct + via group).
     */
    /**
     * Whether the space's **account** can take another member.
     *
     * Counted per organization rather than per space (#billing Phase 2). The
     * old per-space rule let a free user hold three spaces of five and pay for
     * none of them, and "5 members per space" was never a sentence anyone could
     * price against. Guests don't consume seats, so this reads as "N free seats
     * per account" — which is how the rest of the industry states it.
     */
    public function canAddMembersToSpace(Space $space, int $count = 1): bool
    {
        $organization = $space->getOrganization();
        if (!$this->enforcementEnabled || $space->getIsPersonal() || null === $organization) {
            return true;
        }

        return $this->canAddMembersToOrganization($organization, $count);
    }

    /**
     * The cap itself. Exposed directly so the organization member endpoint —
     * the one place a seat can be taken without touching a space at all —
     * enforces the same rule rather than a parallel one.
     */
    public function canAddMembersToOrganization(Organization $organization, int $count = 1): bool
    {
        if (!$this->enforcementEnabled) {
            return true;
        }
        // Paid plans lift the ceiling entirely.
        $limit = $this->planGate->organizationEntitlements($organization)->limit('space_members');
        if (null === $limit) {
            return true;
        }

        return ($organization->seatCount() + $count) <= $limit;
    }
}
