<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserUsageCounter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserUsageCounter>
 */
class UserUsageCounterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserUsageCounter::class);
    }

    /**
     * Delete counter buckets older than the given cutoff day. Returns the
     * number of rows removed. Used by retention once the day has been rolled
     * up into a {@see \App\Entity\UserUsageSnapshot}.
     */
    public function pruneBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.day < :cutoff')
            ->setParameter('cutoff', $cutoff->format('Y-m-d'))
            ->getQuery()
            ->execute();
    }
}
