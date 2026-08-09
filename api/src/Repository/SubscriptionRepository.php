<?php

namespace App\Repository;

use App\Doctrine\SpaceMembershipDql;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 *
 * Resolution is **account-first** (#billing Phase 1b): a subscription belongs to
 * an {@see Organization} or a {@see User} (personal). The legacy per-space rows
 * are still honoured as a transitional fallback until they're dropped.
 */
final class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /** The organization's current entitling subscription (newest), or null. */
    public function findActiveForOrganization(Organization $organization): ?Subscription
    {
        // A deleted org stops entitling the moment it's deleted, not when the
        // cancellation webhook lands. Reading the flag here rather than waiting
        // for `status` to flip closes the window where the account is gone from
        // every listing but its members still hold its plan.
        if ($organization->isDeleted()) {
            return null;
        }

        return $this->newestEntitling('s.organization = :account', 'account', $organization);
    }

    /** The user's personal entitling subscription (newest), or null. */
    public function findActiveForUser(User $user): ?Subscription
    {
        return $this->newestEntitling('s.ownerUser = :account', 'account', $user);
    }

    /**
     * The subscription that governs a space: its organization account, else a
     * legacy space-owned row, else the creator's personal account.
     */
    public function findActiveForSpace(Space $space): ?Subscription
    {
        $organization = $space->getOrganization();
        if (null !== $organization) {
            return $this->findActiveForOrganization($organization);
        }
        $legacy = $this->newestEntitling('s.space = :account', 'account', $space);
        if (null !== $legacy) {
            return $legacy;
        }
        $creator = $space->getCreatedBy();

        return null === $creator ? null : $this->findActiveForUser($creator);
    }

    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
    }

    /**
     * The user's own (personal-account) subscriptions that are still live at
     * Stripe and so worth cancelling before the account is deleted — status is
     * still entitling and a Stripe id is present. Org/space subscriptions are
     * deliberately excluded: those belong to accounts that outlive the user.
     *
     * @return list<Subscription>
     */
    public function findCancelableForUser(User $user): array
    {
        /** @var list<Subscription> $result */
        $result = $this->createQueryBuilder('s')
            ->where('s.ownerUser = :user')
            ->andWhere('s.status IN (:statuses)')
            ->andWhere('s.stripeSubscriptionId IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('statuses', Subscription::ENTITLING_STATUSES)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * True if the user is entitled through any account they belong to.
     */
    public function userHasActiveEntitlement(User $user): bool
    {
        return [] !== $this->entitlingPlansForUser($user);
    }

    /**
     * Distinct plans the user is entitled to, across every account they belong
     * to: organizations they're a member of, their personal account, and any
     * legacy space-owned subscriptions reachable through space membership.
     *
     * @return list<string>
     */
    public function entitlingPlansForUser(User $user): array
    {
        $em = $this->getEntityManager();

        $orgPlans = $em->createQuery(sprintf(
            'SELECT DISTINCT s.plan FROM %s s JOIN s.organization o JOIN o.memberships m'
            . ' WHERE m.user = :user AND s.status IN (:statuses) AND o.deletedAt IS NULL',
            Subscription::class,
        ))->setParameter('user', $user)->setParameter('statuses', Subscription::ENTITLING_STATUSES)
            ->getSingleColumnResult();

        $personalPlans = $em->createQuery(sprintf(
            'SELECT DISTINCT s.plan FROM %s s WHERE s.ownerUser = :user AND s.status IN (:statuses)',
            Subscription::class,
        ))->setParameter('user', $user)->setParameter('statuses', Subscription::ENTITLING_STATUSES)
            ->getSingleColumnResult();

        $legacyPlans = $em->createQuery(sprintf(
            'SELECT DISTINCT s.plan FROM %s s WHERE s.space IS NOT NULL AND s.status IN (:statuses) AND %s',
            Subscription::class,
            SpaceMembershipDql::userBelongsToBoardSpace('s'),
        ))->setParameter('user', $user)->setParameter('statuses', Subscription::ENTITLING_STATUSES)
            ->getSingleColumnResult();

        $merged = array_merge($orgPlans, $personalPlans, $legacyPlans);

        return array_values(array_unique(array_filter($merged, 'is_string')));
    }

    private function newestEntitling(string $predicate, string $param, mixed $value): ?Subscription
    {
        /** @var Subscription|null $result */
        $result = $this->createQueryBuilder('s')
            ->where($predicate)
            ->andWhere('s.status IN (:statuses)')
            ->setParameter($param, $value)
            ->setParameter('statuses', Subscription::ENTITLING_STATUSES)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }
}
