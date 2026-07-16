<?php

namespace App\Repository;

use App\Entity\Engagement;
use App\Entity\TimeEntry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimeEntry>
 */
class TimeEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeEntry::class);
    }

    /**
     * The user's currently-running timer (endedAt IS NULL), if any. The partial
     * unique index guarantees at most one, so this is deterministic.
     */
    public function findRunningForUser(User $user): ?TimeEntry
    {
        $result = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.endedAt IS NULL')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        assert(null === $result || $result instanceof TimeEntry);

        return $result;
    }

    /**
     * Completed, billable, not-yet-billed entries on a engagement — the pool
     * an invoice draws from. Ordered by category then start so line items group.
     * An optional [$from, $toExclusive) window filters on the entry's startedAt
     * (callers pass the day AFTER the last wanted date as $toExclusive, so a
     * user-facing inclusive date range maps cleanly onto timestamps).
     *
     * @return list<TimeEntry>
     */
    public function findInvoiceableForEngagement(
        Engagement $engagement,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $toExclusive = null,
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.engagement = :bp')
            ->andWhere('t.billable = true')
            ->andWhere('t.endedAt IS NOT NULL')
            ->andWhere('t.billedAt IS NULL')
            ->setParameter('bp', $engagement)
            ->leftJoin('t.category', 'c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('t.startedAt', 'ASC');

        if (null !== $from) {
            $qb->andWhere('t.startedAt >= :from')->setParameter('from', $from);
        }
        if (null !== $toExclusive) {
            $qb->andWhere('t.startedAt < :toExclusive')->setParameter('toExclusive', $toExclusive);
        }

        /** @var list<TimeEntry> */
        return $qb->getQuery()->getResult();
    }
}
