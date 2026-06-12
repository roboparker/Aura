<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserUsageSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserUsageSnapshot>
 */
class UserUsageSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserUsageSnapshot::class);
    }
}
