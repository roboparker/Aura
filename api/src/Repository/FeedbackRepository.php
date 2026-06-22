<?php

namespace App\Repository;

use App\Entity\Feedback;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Feedback>
 */
final class FeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feedback::class);
    }

    /**
     * Next value of the dedicated `feedback_ticket_seq` sequence. Used by
     * {@see \App\State\FeedbackOwnerProcessor} to stamp a concurrency-safe
     * monotonic ticket number on each new ticket.
     */
    public function nextTicketNumber(): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $value = $connection->fetchOne("SELECT nextval('feedback_ticket_seq')");

        return is_numeric($value) ? (int) $value : 0;
    }
}
