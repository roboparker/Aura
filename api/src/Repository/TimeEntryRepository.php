<?php

namespace App\Repository;

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
}
