<?php

namespace App\Repository;

use App\Entity\Estimate;
use App\Entity\Space;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Estimate>
 */
class EstimateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Estimate::class);
    }

    /** Count estimates already numbered in a space — basis for the next EST number. */
    public function countNumbered(Space $space): int
    {
        $count = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.space = :space')
            ->andWhere('e.number IS NOT NULL')
            ->setParameter('space', $space)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /** Resolve an estimate from a public-link token (caller passes the sha256 hash). */
    public function findByPublicTokenHash(string $hash): ?Estimate
    {
        return $this->findOneBy(['publicToken' => $hash]);
    }
}
