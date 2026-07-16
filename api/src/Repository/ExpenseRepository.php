<?php

namespace App\Repository;

use App\Entity\Engagement;
use App\Entity\Expense;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Expense>
 */
class ExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expense::class);
    }

    /**
     * Billable, not-yet-billed expenses on an engagement — the pool invoice
     * generation (#644/#650) pulls onto the invoice. An optional
     * [$from, $toExclusive) window filters on spentOn, mirroring the
     * time-entry pool's startedAt window.
     *
     * @return list<Expense>
     */
    public function findInvoiceableForEngagement(
        Engagement $engagement,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $toExclusive = null,
    ): array {
        $qb = $this->createQueryBuilder('x')
            ->andWhere('x.engagement = :bp')
            ->andWhere('x.billable = true')
            ->andWhere('x.billedAt IS NULL')
            ->setParameter('bp', $engagement)
            ->orderBy('x.spentOn', 'ASC')
            ->addOrderBy('x.createdAt', 'ASC');

        if (null !== $from) {
            $qb->andWhere('x.spentOn >= :from')->setParameter('from', $from);
        }
        if (null !== $toExclusive) {
            $qb->andWhere('x.spentOn < :toExclusive')->setParameter('toExclusive', $toExclusive);
        }

        /** @var list<Expense> */
        return $qb->getQuery()->getResult();
    }
}
