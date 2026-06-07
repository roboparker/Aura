<?php

namespace App\Service;

use App\Entity\Segment;
use App\Entity\SegmentMembership;
use App\Entity\User;
use App\Repository\SegmentMembershipRepository;
use App\Repository\SegmentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Decides whether a user belongs to a segment at request time. Membership is
 * never stored on the user — it's recomputed from the segment's live config
 * so raising a rollout percentage or flipping a role rule takes effect
 * immediately, with no backfill.
 *
 * Precedence (first match wins):
 *   1. segment disabled            → out
 *   2. manual "exclude" override   → out   (per-user kill switch)
 *   3. manual "include" override   → in    (hand-picked member)
 *   4. user's roles ∩ includedRoles → in   (e.g. all admins)
 *   5. rollout bucket < percentage → in    (deterministic hash of key:userId)
 *   6. otherwise                   → out
 *
 * Per-request memoisation keeps the bulk path ({@see activeSegmentKeysFor})
 * to two queries regardless of how many segments exist.
 */
final class SegmentEvaluator
{
    /**
     * userId → (segmentId → manual mode). Memoised for the lifetime of the
     * request so the voter and the /api/me serializer don't re-query.
     *
     * @var array<string, array<string, string>>
     */
    private array $modeCache = [];

    public function __construct(
        private SegmentRepository $segments,
        private SegmentMembershipRepository $memberships,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Live `is_granted()`-style check for a single segment by key. Backs
     * {@see \App\Security\SegmentVoter}.
     */
    public function isUserInSegment(User $user, string $key): bool
    {
        $segment = $this->segments->findOneByKey($key);
        if (null === $segment) {
            return false;
        }

        return $this->evaluate($segment, $user, $this->manualModeMap($user));
    }

    /**
     * Keys of every enabled segment the user currently belongs to.
     *
     * @return list<string>
     */
    public function activeSegmentKeysFor(User $user): array
    {
        $map = $this->manualModeMap($user);
        $keys = [];
        foreach ($this->segments->findAllEnabled() as $segment) {
            if ($this->evaluate($segment, $user, $map)) {
                $keys[] = $segment->getKey();
            }
        }
        return $keys;
    }

    /**
     * The synthetic `ROLE_SEGMENT_*` roles for the user's active segments —
     * appended to the `/api/me` roles array so the PWA gates with the same
     * role strings the server uses.
     *
     * @return list<string>
     */
    public function activeSegmentRolesFor(User $user): array
    {
        return array_map(
            static fn (string $key): string => Segment::keyToRole($key),
            $this->activeSegmentKeysFor($user),
        );
    }

    /**
     * Exact reach of a segment over the (non-waitlisted) user base, with a
     * breakdown of which rule pulled each user in. Admin-only and computed
     * on demand, so the full scan is acceptable.
     *
     * @return array{
     *     total: int,
     *     manualIncludes: int,
     *     manualExcludes: int,
     *     byRole: int,
     *     byRollout: int
     * }
     */
    public function reach(Segment $segment): array
    {
        $includedIds = [];
        $excludedIds = [];
        foreach ($segment->getMemberships() as $membership) {
            $rowUser = $membership->getUser();
            if (null === $rowUser || null === $rowUser->getId()) {
                continue;
            }
            $uid = (string) $rowUser->getId();
            if (SegmentMembership::MODE_EXCLUDE === $membership->getMode()) {
                $excludedIds[$uid] = true;
            } else {
                $includedIds[$uid] = true;
            }
        }

        $total = 0;
        $byRole = 0;
        $byRollout = 0;
        $manualIncludes = 0;

        foreach ($this->em->getRepository(User::class)->findBy(['waitlisted' => false]) as $user) {
            if (null === $user->getId()) {
                continue;
            }
            $uid = (string) $user->getId();
            if (isset($excludedIds[$uid])) {
                continue;
            }

            if (isset($includedIds[$uid])) {
                ++$manualIncludes;
            } elseif ($this->matchesRole($segment, $user)) {
                ++$byRole;
            } elseif ($this->matchesRollout($segment, $user)) {
                ++$byRollout;
            } else {
                continue;
            }
            ++$total;
        }

        return [
            'total' => $total,
            'manualIncludes' => $manualIncludes,
            'manualExcludes' => count($excludedIds),
            'byRole' => $byRole,
            'byRollout' => $byRollout,
        ];
    }

    /**
     * @param array<string, string> $manualModeMap segmentId → mode
     */
    public function evaluate(Segment $segment, User $user, array $manualModeMap): bool
    {
        if (!$segment->isEnabled() || null === $segment->getId()) {
            return false;
        }

        $mode = $manualModeMap[(string) $segment->getId()] ?? null;
        if (SegmentMembership::MODE_EXCLUDE === $mode) {
            return false;
        }
        if (SegmentMembership::MODE_INCLUDE === $mode) {
            return true;
        }

        return $this->matchesRole($segment, $user) || $this->matchesRollout($segment, $user);
    }

    private function matchesRole(Segment $segment, User $user): bool
    {
        $included = $segment->getIncludedRoles();
        if ([] === $included) {
            return false;
        }

        return [] !== array_intersect($included, $user->getRoles());
    }

    private function matchesRollout(Segment $segment, User $user): bool
    {
        $pct = $segment->getRolloutPercentage();
        if (null === $pct || $pct <= 0 || null === $user->getId()) {
            return false;
        }

        return $this->rolloutBucket($segment->getKey(), (string) $user->getId()) < $pct;
    }

    /**
     * Deterministic 0–99 bucket from a hash of `key:userId`. Stable across
     * requests and monotonic in the percentage (a user in at 20% is still in
     * at 30%), so raising a rollout never drops anyone.
     */
    private function rolloutBucket(string $key, string $userId): int
    {
        return hexdec(substr(hash('sha256', $key . ':' . $userId), 0, 8)) % 100;
    }

    /**
     * @return array<string, string> segmentId → manual mode
     */
    private function manualModeMap(User $user): array
    {
        $uid = (string) $user->getId();
        if (isset($this->modeCache[$uid])) {
            return $this->modeCache[$uid];
        }

        $map = [];
        foreach ($this->memberships->findForUser($user) as $membership) {
            $segment = $membership->getSegment();
            if (null !== $segment && null !== $segment->getId()) {
                $map[(string) $segment->getId()] = $membership->getMode();
            }
        }

        return $this->modeCache[$uid] = $map;
    }
}
