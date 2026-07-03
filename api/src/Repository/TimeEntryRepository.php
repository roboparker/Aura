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
     * Completed, billable, not-yet-billed entries in a space — the pool an
     * invoice draws from. Optionally narrowed to one project.
     *
     * @return list<TimeEntry>
     */
    public function findInvoiceable(Space $space, ?Project $project = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.space = :space')
            ->andWhere('t.billable = true')
            ->andWhere('t.endedAt IS NOT NULL')
            ->andWhere('t.billedAt IS NULL')
            ->setParameter('space', $space)
            ->orderBy('t.startedAt', 'ASC');
        if (null !== $project) {
            $qb->andWhere('t.project = :project')->setParameter('project', $project);
        }

        return $qb->getQuery()->getResult();
    }
}
