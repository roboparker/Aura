<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Automation;
use App\Entity\Board;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Automation>
 */
class AutomationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Automation::class);
    }

    /** @return list<Automation> */
    public function forBoard(Board $board): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.board = :board')
            ->setParameter('board', $board)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Enabled rules on this board listening for this event — the indexed lookup
     * that keeps an ordinary task edit from deserialising every rule.
     *
     * @return list<Automation>
     */
    public function enabledFor(Board $board, string $event): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.board = :board')
            ->andWhere('a.triggerEvent = :event')
            ->andWhere('a.enabled = true')
            ->setParameter('board', $board)
            ->setParameter('event', $event)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForBoard(Board $board): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.board = :board')
            ->setParameter('board', $board)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
