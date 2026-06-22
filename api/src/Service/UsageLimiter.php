<?php

declare(strict_types=1);

namespace App\Service;

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

        return false === $count ? 0 : (int) $count;
    }

    /**
     * Remaining free MCP calls for today, or null when unlimited (entitled
     * or enforcement disabled). Floored at zero.
     */
    public function remainingMcpCalls(User $user): ?int
    {
        if (!$this->enforcementEnabled || $this->isUserEntitled($user)) {
            return null;
        }

        return max(0, $this->freeMcpDailyLimit - $this->mcpCallsToday($user));
    }

    /**
     * Whether the user may make one more MCP call right now. Entitled users
     * and (while the gate is dark) everyone are always allowed.
     */
    public function isMcpCallAllowed(User $user): bool
    {
        if (!$this->enforcementEnabled || $this->isUserEntitled($user)) {
            return true;
        }

        return $this->mcpCallsToday($user) < $this->freeMcpDailyLimit;
    }

    /**
     * Whether {@see $count} more member(s) can be added to a space under the
     * free ceiling. Personal spaces (always solo) and subscribed spaces are
     * unbounded. Counts the current effective members (direct + via group).
     */
    public function canAddMembersToSpace(Space $space, int $count = 1): bool
    {
        if (!$this->enforcementEnabled || $space->getIsPersonal()) {
            return true;
        }
        if (null !== $this->subscriptions->findActiveForSpace($space)) {
            return true;
        }

        $current = \count($space->getEffectiveUsers());

        return ($current + $count) <= $this->freeSpaceMemberLimit;
    }
}
