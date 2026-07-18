<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Space;
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
     * Completed entries in a space for the reports (#647), relations
     * fetch-joined so per-row label lookups don't N+1. Optional
     * [$from, $toExclusive) window on startedAt.
     *
     * @return list<TimeEntry>
     */
    public function findCompletedForSpace(
        Space $space,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $toExclusive = null,
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('e', 'c', 'cl', 'u')
            ->leftJoin('t.project', 'e')
            ->leftJoin('t.category', 'c')
            ->leftJoin('e.client', 'cl')
            ->leftJoin('t.user', 'u')
            ->andWhere('t.space = :space')
            ->andWhere('t.endedAt IS NOT NULL')
            ->setParameter('space', $space)
            ->orderBy('t.startedAt', 'ASC');

        if (null !== $from) {
            $qb->andWhere('t.startedAt >= :from')->setParameter('from', $from);
        }
        if (null !== $toExclusive) {
            $qb->andWhere('t.startedAt < :toExclusive')->setParameter('toExclusive', $toExclusive);
        }

        /** @var list<TimeEntry> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Unbilled billable completed entries across a whole space — the
     * "what can I bill right now" pool the uninvoiced report (#647) groups
     * per project. Entries without an project can't be invoiced, so
     * they're excluded.
     *
     * @return list<TimeEntry>
     */
    public function findUninvoicedForSpace(Space $space): array
    {
        /** @var list<TimeEntry> */
        return $this->createQueryBuilder('t')
            ->addSelect('e', 'cl')
            ->join('t.project', 'e')
            ->leftJoin('e.client', 'cl')
            ->andWhere('t.space = :space')
            ->andWhere('t.billable = true')
            ->andWhere('t.endedAt IS NOT NULL')
            ->andWhere('t.billedAt IS NULL')
            ->setParameter('space', $space)
            ->orderBy('t.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Completed, billable, not-yet-billed entries on a project — the pool
     * an invoice draws from. Ordered by category then start so line items group.
     * An optional [$from, $toExclusive) window filters on the entry's startedAt
     * (callers pass the day AFTER the last wanted date as $toExclusive, so a
     * user-facing inclusive date range maps cleanly onto timestamps).
     *
     * @return list<TimeEntry>
     */
    public function findInvoiceableForProject(
        Project $project,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $toExclusive = null,
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.project = :bp')
            ->andWhere('t.billable = true')
            ->andWhere('t.endedAt IS NOT NULL')
            ->andWhere('t.billedAt IS NULL')
            ->setParameter('bp', $project)
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
