<?php

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * Budgeted, non-archived projects — the nightly alert sweep's pool (#651).
     *
     * @return list<Project>
     */
    public function findBudgeted(): array
    {
        /** @var list<Project> */
        return $this->createQueryBuilder('e')
            ->andWhere('e.budgetType IS NOT NULL')
            ->andWhere('e.budgetAmount IS NOT NULL')
            ->andWhere('e.archived = false')
            ->getQuery()
            ->getResult();
    }
}
