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

    /** Resolve an invoice from a public-link token (caller passes the sha256 hash). */
    public function findByPublicTokenHash(string $hash): ?Invoice
    {
        return $this->findOneBy(['publicToken' => $hash]);
    }

    /**
     * Recurring-template invoices whose next issue date has arrived.
     *
     * @return list<Invoice>
     */
    public function findDueRecurring(\DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.recurrenceFrequency IS NOT NULL')
            ->andWhere('i.nextIssueDate IS NOT NULL')
            ->andWhere('i.nextIssueDate <= :today')
            ->setParameter('today', $today)
            ->getQuery()
            ->getResult();
    }

    /**
     * Flip every sent invoice whose due date has passed to overdue, in one bulk
     * UPDATE. Returns how many were changed. Paid/void/draft are left alone.
     */
    public function markOverdue(\DateTimeImmutable $today): int
    {
        return $this->createQueryBuilder('i')
            ->update()
            ->set('i.status', ':overdue')
            ->where('i.status = :sent')
            ->andWhere('i.dueDate IS NOT NULL')
            ->andWhere('i.dueDate < :today')
            ->setParameter('overdue', Invoice::STATUS_OVERDUE)
            ->setParameter('sent', Invoice::STATUS_SENT)
            ->setParameter('today', $today)
            ->getQuery()
            ->execute();
    }
}
