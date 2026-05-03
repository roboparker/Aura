<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\TaskRepository;
use App\Validator\ValidReminders;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Walks every active recurring or due-soon task and creates Notification
 * rows for any reminder offsets that have come due since the last run.
 *
 * Recipients = task owner + assignees (one notification each). Idempotency
 * is enforced two ways: a SELECT before INSERT to avoid the round-trip,
 * and a unique index on (recipient, task, offset) as a hard backstop so
 * concurrent runs can't double up.
 *
 * Intended to be wired to a cron (e.g. every 5 minutes) in production.
 * Safe to run by hand at any time — no state outside the notification
 * table.
 */
#[AsCommand(
    name: 'app:tasks:reminders:dispatch',
    description: 'Create in-app notifications for any task reminders that have come due.',
)]
final class DispatchTaskRemindersCommand extends Command
{
    public function __construct(
        private TaskRepository $tasks,
        private NotificationRepository $notifications,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        // We could narrow this by `dueDate >= now - 7 days` to bound the
        // scan, but for an MVP the dataset is small enough that the
        // simpler full scan keeps the logic readable.
        $candidates = $this->tasks->createQueryBuilder('t')
            ->where('t.completedOn IS NULL')
            ->andWhere('t.dueDate IS NOT NULL')
            ->andWhere('t.reminders IS NOT NULL')
            ->getQuery()
            ->getResult();

        $created = 0;
        /** @var Task $task */
        foreach ($candidates as $task) {
            $reminders = $task->getReminders() ?? [];
            $dueDate = $task->getDueDate();
            if (null === $dueDate || [] === $reminders) {
                continue;
            }

            $recipients = $this->collectRecipients($task);
            if ([] === $recipients) {
                continue;
            }

            foreach ($reminders as $offset) {
                $fireAt = $this->subtractOffset($dueDate, $offset);
                if (null === $fireAt || $fireAt > $now) {
                    continue;
                }

                foreach ($recipients as $recipient) {
                    if ($this->notifications->taskReminderExists($recipient, $task, $offset)) {
                        continue;
                    }

                    $notification = new Notification();
                    $notification->setRecipient($recipient);
                    $notification->setTask($task);
                    $notification->setReminderOffset($offset);
                    $notification->setType(Notification::TYPE_TASK_REMINDER);
                    $notification->setTitle(sprintf('Reminder: %s', $task->getTitle()));
                    $notification->setBody($this->buildBody($task, $offset));
                    $this->em->persist($notification);

                    try {
                        $this->em->flush();
                        ++$created;
                    } catch (UniqueConstraintViolationException) {
                        // Concurrent dispatcher beat us to it. Recover the
                        // EM, drop our pending entity, and move on — the
                        // notification still landed, just from the other
                        // worker.
                        $this->em->clear();
                    }
                }
            }
        }

        $io->success(sprintf('Created %d notification(s).', $created));
        return Command::SUCCESS;
    }

    /**
     * @return User[]
     */
    private function collectRecipients(Task $task): array
    {
        $seen = [];
        $owner = $task->getOwner();
        if (null !== $owner && null !== $owner->getId()) {
            $seen[(string) $owner->getId()] = $owner;
        }
        foreach ($task->getAssignees() as $assignee) {
            if (null !== $assignee->getId()) {
                $seen[(string) $assignee->getId()] = $assignee;
            }
        }
        return array_values($seen);
    }

    private function subtractOffset(\DateTimeImmutable $dueDate, string $offset): ?\DateTimeImmutable
    {
        if (!in_array($offset, ValidReminders::ALLOWED_OFFSETS, true)) {
            return null;
        }
        return match ($offset) {
            '15m' => $dueDate->modify('-15 minutes'),
            '1h' => $dueDate->modify('-1 hour'),
            '1d' => $dueDate->modify('-1 day'),
            default => null,
        };
    }

    private function buildBody(Task $task, string $offset): string
    {
        $human = match ($offset) {
            '15m' => '15 minutes',
            '1h' => '1 hour',
            '1d' => '1 day',
            default => $offset,
        };
        $due = $task->getDueDate()?->format('M j, Y g:i a') ?? '';
        return sprintf('"%s" is due in %s (%s).', $task->getTitle(), $human, $due);
    }
}
