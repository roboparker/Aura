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
}
