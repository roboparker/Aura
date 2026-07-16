<?php

namespace App\Repository;

use App\Entity\Engagement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Engagement>
 */
class EngagementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Engagement::class);
    }

    /**
     * Budgeted, non-archived engagements — the nightly alert sweep's pool (#651).
     *
     * @return list<Engagement>
     */
    public function findBudgeted(): array
    {
        /** @var list<Engagement> */
        return $this->createQueryBuilder('e')
            ->andWhere('e.budgetType IS NOT NULL')
            ->andWhere('e.budgetAmount IS NOT NULL')
            ->andWhere('e.archived = false')
            ->getQuery()
            ->getResult();
    }
}
