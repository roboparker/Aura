<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DashboardWidget;
use App\Entity\Space;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DashboardWidget>
 */
class DashboardWidgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DashboardWidget::class);
    }

    /**
     * A space's widgets in layout order.
     *
     * @return list<DashboardWidget>
     */
    public function forSpace(Space $space): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.space = :space')
            ->setParameter('space', $space)
            ->orderBy('w.position', 'ASC')
            ->addOrderBy('w.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForSpace(Space $space): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.space = :space')
            ->setParameter('space', $space)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** The next free slot, so a newly added widget lands at the end. */
    public function nextPosition(Space $space): int
    {
        $max = $this->createQueryBuilder('w')
            ->select('MAX(w.position)')
            ->where('w.space = :space')
            ->setParameter('space', $space)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : ((int) $max) + 1;
    }
}
