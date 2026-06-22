<?php

namespace App\Repository;

use App\Doctrine\SpaceMembershipDql;
use App\Entity\Space;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
final class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * The space's current entitling subscription, or null if it's on the
     * free plan. Filters to {@see Subscription::ENTITLING_STATUSES} and takes
     * the newest, since a space can carry historical (canceled) rows.
     */
    public function findActiveForSpace(Space $space): ?Subscription
    {
        /** @var Subscription|null $result */
        $result = $this->createQueryBuilder('s')
            ->where('s.space = :space')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('space', $space)
            ->setParameter('statuses', Subscription::ENTITLING_STATUSES)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Look a subscription up by its Stripe id — the webhook handler's
     * primary key into our mirror of Stripe state.
     */
    public function findByStripeSubscriptionId(string $stripeSubscriptionId): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
    }

    /**
     * True if the user belongs (directly or via a group) to at least one
     * space with an entitling subscription — i.e. they get unlimited
     * automation throughput. Reuses the shared space-membership EXISTS
     * fragment so the "(direct OR via group)" rule stays in one place.
     */
    public function userHasActiveEntitlement(User $user): bool
    {
        $dql = sprintf(
            'SELECT COUNT(s.id) FROM %s s WHERE s.status IN (:statuses) AND %s',
            Subscription::class,
            SpaceMembershipDql::userBelongsToProjectSpace('s'),
        );

        $count = (int) $this->getEntityManager()
            ->createQuery($dql)
            ->setParameter('statuses', Subscription::ENTITLING_STATUSES)
            ->setParameter('user', $user)
            ->getSingleScalarResult();

        return $count > 0;
    }
}
