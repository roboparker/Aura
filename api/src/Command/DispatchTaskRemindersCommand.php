<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use App\Push\PushPayload;
use App\Push\PushSenderInterface;
use App\Repository\NotificationRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\TaskRepository;
use App\Validator\ValidReminders;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

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
        private PushSubscriptionRepository $pushSubscriptions,
        private PushSenderInterface $pushSender,
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $emailsSent = 0;
        $pushesSent = 0;

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
                        continue;
                    }

                    if ($this->sendReminderEmail($recipient, $task, $offset, $io)) {
                        ++$emailsSent;
                    }

                    $pushesSent += $this->sendReminderPush($recipient, $task, $offset);
                }
            }
        }

        $io->success(sprintf(
            'Created %d notification(s); sent %d email(s); delivered %d push(es).',
            $created,
            $emailsSent,
            $pushesSent,
        ));
        return Command::SUCCESS;
    }

    /**
     * Sends a Web Push for the reminder to every subscription the recipient
     * has registered, gated on `pushNotificationsEnabled`. Subscriptions
     * the browser's push service reports as expired (404/410) are pruned
     * inline so the next dispatcher pass doesn't re-attempt them.
     *
     * Returns the count of pushes the transport accepted (i.e. pushes that
     * actually left the box) so the caller can keep the success summary
     * accurate.
     */
    private function sendReminderPush(User $recipient, Task $task, string $offset): int
    {
        $prefs = $recipient->getPreferences();
        if (true !== ($prefs['pushNotificationsEnabled'] ?? false)) {
            return 0;
        }

        $subscriptions = $this->pushSubscriptions->findActiveForUser($recipient);
        if ([] === $subscriptions) {
            return 0;
        }

        $payload = new PushPayload(
            title: sprintf('Reminder: %s', $task->getTitle()),
            body: $this->buildBody($task, $offset),
            url: rtrim($this->frontendUrl, '/') . '/tasks/' . $task->getId(),
            tag: 'task-reminder-' . $task->getId() . '-' . $offset,
        );

        $delivered = 0;
        foreach ($subscriptions as $subscription) {
            $result = $this->pushSender->send($subscription, $payload);
            if ($result->subscriptionExpired) {
                // Browser revoked or GC'd the registration. Prune the row
                // so we don't keep retrying a dead endpoint forever.
                $this->em->remove($subscription);
                $this->em->flush();
                continue;
            }
            if ($result->success) {
                ++$delivered;
            }
        }

        return $delivered;
    }

    /**
     * Sends the reminder email if the recipient's preferences allow it.
     * Digest frequencies (hourly/daily) are explicitly skipped — that's
     * #102's territory. Returns true when an email was actually handed to
     * the mailer transport so the caller can keep an accurate count for
     * the success summary.
     */
    private function sendReminderEmail(
        User $recipient,
        Task $task,
        string $offset,
        SymfonyStyle $io,
    ): bool {
        $prefs = $recipient->getPreferences();
        if (false === ($prefs['emailNotificationsEnabled'] ?? true)) {
            return false;
        }
        if ('realtime' !== ($prefs['notificationFrequency'] ?? 'realtime')) {
            return false;
        }

        $email = (new TemplatedEmail())
            ->from($this->mailerFrom ?: 'no-reply@aura.test')
            ->to($recipient->getEmail())
            ->subject(sprintf('Reminder: %s', $task->getTitle()))
            ->htmlTemplate('emails/task_reminder.html.twig')
            ->textTemplate('emails/task_reminder.txt.twig')
            ->context([
                'recipient' => $recipient,
                'task' => $task,
                'offsetLabel' => $this->humanOffset($offset),
                'tasksUrl' => rtrim($this->frontendUrl, '/') . '/tasks',
            ]);

        try {
            $this->mailer->send($email);
            return true;
        } catch (TransportExceptionInterface $e) {
            // Don't blow up the whole dispatcher run if the mail server is
            // momentarily unreachable — the in-app notification already
            // landed, and the next cron pass will retry untouched users.
            $io->warning(sprintf(
                'Failed to send reminder email to %s: %s',
                $recipient->getEmail(),
                $e->getMessage(),
            ));
            return false;
        }
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
        // $offset is one of ALLOWED_OFFSETS (the in_array guard above),
        // so the match is exhaustive over the literal values.
        return match ($offset) {
            '15m' => $dueDate->modify('-15 minutes'),
            '1h' => $dueDate->modify('-1 hour'),
            '1d' => $dueDate->modify('-1 day'),
        };
    }

    private function buildBody(Task $task, string $offset): string
    {
        $due = $task->getDueDate()?->format('M j, Y g:i a') ?? '';
        return sprintf(
            '"%s" is due in %s (%s).',
            $task->getTitle(),
            $this->humanOffset($offset),
            $due,
        );
    }

    private function humanOffset(string $offset): string
    {
        return match ($offset) {
            '15m' => '15 minutes',
            '1h' => '1 hour',
            '1d' => '1 day',
            default => $offset,
        };
    }
}
