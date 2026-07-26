<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AutomationRun>
 */
class AutomationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutomationRun::class);
    }

    /** @return list<AutomationRun> */
    public function recentFor(Automation $automation, int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.automation = :automation')
            ->setParameter('automation', $automation)
            ->orderBy('r.ranAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * How many times a rule has run since `$since` — the input to the runaway
     * check, which is the only defence that catches a rule firing far more
     * often than its author expected.
     */
    public function countSince(Automation $automation, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.automation = :automation')
            ->andWhere('r.ranAt >= :since')
            ->setParameter('automation', $automation)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Retention prune — this is a debugging aid, not an audit log. */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.ranAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
