<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Idempotency check used by the reminder dispatcher: a row already
     * exists for this exact (recipient, task, offset) tuple so we should
     * not create a duplicate. The unique index makes accidental duplicates
     * fail loudly at the DB level too.
     */
    public function taskReminderExists(User $recipient, Task $task, string $offset): bool
    {
        return null !== $this->findOneBy([
            'recipient' => $recipient,
            'task' => $task,
            'reminderOffset' => $offset,
        ]);
    }

    /**
     * Returns notifications for a recipient that have not yet been
     * included in any digest email — what the digest dispatcher needs to
     * roll up into the next outgoing message. Ordered oldest-first so
     * the email reads chronologically.
     *
     * @return Notification[]
     */
    public function findPendingDigestForRecipient(User $recipient): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.digestedAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->orderBy('n.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
