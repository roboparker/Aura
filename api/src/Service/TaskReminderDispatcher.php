<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use App\Push\PushPayload;
use App\Push\PushSenderInterface;
use App\Repository\NotificationRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\TaskRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Walks every active task with reminders and creates Notification rows for
 * any reminder fires that have come due since the last run.
 *
 * Reminders are relative ("15 minutes before due") or absolute ("on Apr 2
 * 9:00am"), each optionally "repeat daily until done". Fire computation +
 * the canonical fire key live in {@see ReminderScheduler} so this dispatcher
 * and the validator agree on what a reminder means.
 *
 * Recipients = task owner + assignees (one notification each). Idempotency
 * is enforced two ways: a SELECT before INSERT to avoid the round-trip, and
 * a unique index on (recipient, task, reminder_offset) as a hard backstop —
 * `reminder_offset` here stores the per-fire key, so a "repeat daily"
 * reminder produces one distinct row per day.
 *
 * Shared by App\Command\DispatchTaskRemindersCommand (manual / one-off
 * runs) and App\MessageHandler\DispatchTaskRemindersHandler (the
 * scheduler's every-5-minutes pass). Safe to run at any time.
 */
final class TaskReminderDispatcher
{
    public function __construct(
        private TaskRepository $tasks,
        private NotificationRepository $notifications,
        private PushSubscriptionRepository $pushSubscriptions,
        private PushSenderInterface $pushSender,
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private ReminderScheduler $scheduler,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    public function dispatch(): TaskReminderDispatchResult
    {
        $now = new \DateTimeImmutable();
        $emailsSent = 0;
        $pushesSent = 0;

        // Absolute reminders fire without a due date, so we no longer filter
        // on dueDate here — only on "incomplete + has reminders".
        $candidates = $this->tasks->createQueryBuilder('t')
            ->where('t.completedOn IS NULL')
            ->andWhere('t.reminders IS NOT NULL')
            ->getQuery()
            ->getResult();

        $created = 0;
        /** @var Task $task */
        foreach ($candidates as $task) {
            $reminders = $task->getReminders() ?? [];
            if ([] === $reminders) {
                continue;
            }

            $recipients = $this->collectRecipients($task);
            if ([] === $recipients) {
                continue;
            }

            $dueDate = $task->getDueDate();
            foreach ($reminders as $reminder) {
                if (!is_array($reminder)) {
                    continue;
                }
                foreach ($this->scheduler->dueFires($reminder, $dueDate, $now) as $fire) {
                    $key = $fire['key'];
                    $label = $this->scheduler->describe($reminder);

                    foreach ($recipients as $recipient) {
                        if ($this->notifications->taskReminderExists($recipient, $task, $key)) {
                            continue;
                        }

                        $notification = new Notification();
                        $notification->setRecipient($recipient);
                        $notification->setTask($task);
                        $notification->setReminderOffset($key);
                        $notification->setType(Notification::TYPE_TASK_REMINDER);
                        $notification->setTitle(sprintf('Reminder: %s', $task->getTitle()));
                        $notification->setBody($this->buildBody($task, $label));
                        $this->em->persist($notification);

                        try {
                            $this->em->flush();
                            ++$created;
                        } catch (UniqueConstraintViolationException) {
                            // Concurrent dispatcher beat us to it. Recover the
                            // EM, drop our pending entity, and move on.
                            $this->em->clear();
                            continue;
                        }

                        if ($this->sendReminderEmail($recipient, $task, $label)) {
                            ++$emailsSent;
                        }

                        $pushesSent += $this->sendReminderPush($recipient, $task, $key, $label);
                    }
                }
            }
        }

        return new TaskReminderDispatchResult($created, $emailsSent, $pushesSent);
    }

    /**
     * Sends a Web Push for the reminder to every subscription the recipient
     * has registered, gated on `pushNotificationsEnabled`. Subscriptions the
     * push service reports as expired (404/410) are pruned inline.
     *
     * Returns the count of pushes the transport accepted.
     */
    private function sendReminderPush(User $recipient, Task $task, string $key, string $label): int
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
            body: $this->buildBody($task, $label),
            url: rtrim($this->frontendUrl, '/') . '/tasks/' . $task->getId(),
            tag: 'task-reminder-' . $task->getId() . '-' . $key,
        );

        $delivered = 0;
        foreach ($subscriptions as $subscription) {
            $result = $this->pushSender->send($subscription, $payload);
            if ($result->subscriptionExpired) {
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
     * Digest frequencies are skipped — that's the digest dispatcher's
     * territory. Returns true when an email was handed to the transport.
     */
    private function sendReminderEmail(User $recipient, Task $task, string $label): bool
    {
        $prefs = $recipient->getPreferences();
        if (false === ($prefs['emailNotificationsEnabled'] ?? true)) {
            return false;
        }
        if ('realtime' !== ($prefs['notificationFrequency'] ?? 'realtime')) {
            return false;
        }

        $email = (new TemplatedEmail())
            ->from((null !== $this->mailerFrom && '' !== $this->mailerFrom) ? $this->mailerFrom : 'no-reply@madori.test')
            ->to($recipient->getEmail())
            ->subject(sprintf('Reminder: %s', $task->getTitle()))
            ->htmlTemplate('emails/task_reminder.html.twig')
            ->textTemplate('emails/task_reminder.txt.twig')
            ->context([
                'recipient' => $recipient,
                'task' => $task,
                'offsetLabel' => $label,
                'tasksUrl' => rtrim($this->frontendUrl, '/') . '/tasks',
            ]);

        try {
            $this->mailer->send($email);
            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning(sprintf(
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

    private function buildBody(Task $task, string $label): string
    {
        $due = $task->getDueDate()?->format('M j, Y g:i a');
        if (null !== $due) {
            return sprintf('"%s" — reminder %s (due %s).', $task->getTitle(), $label, $due);
        }
        return sprintf('"%s" — reminder %s.', $task->getTitle(), $label);
    }
}
