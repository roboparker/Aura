<?php

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Space;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * Count invoices already issued a number in a space — the basis for the next
     * sequential number when an invoice is issued.
     */
    public function countNumbered(Space $space): int
    {
        $count = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.space = :space')
            ->andWhere('i.number IS NOT NULL')
            ->setParameter('space', $space)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
