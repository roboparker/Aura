<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminActionLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminActionLog>
 */
final class AdminActionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminActionLog::class);
    }

    /**
     * Most recent admin actions, newest first.
     *
     * @return list<AdminActionLog>
     */
    public function recent(int $limit = 100): array
    {
        /** @var list<AdminActionLog> $rows */
        $rows = $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
