<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\PushSubscription;
use App\Entity\Task;
use App\Entity\User;
use App\Push\PushPayload;
use App\Push\PushSenderInterface;
use App\Repository\TaskRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
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
        private ManagerRegistry $doctrine,
        private PushSenderInterface $pushSender,
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
        $created = 0;
        $emailsSent = 0;
        $pushesSent = 0;

        // Absolute reminders fire without a due date, so we only filter on
        // "incomplete + has reminders". Select IDs (not entities) so that a
        // manager reset mid-run never leaves us iterating detached Task
        // objects — each task is re-fetched fresh against the current manager.
        /** @var list<mixed> $candidateIds */
        $candidateIds = $this->tasks->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.completedOn IS NULL')
            ->andWhere('t.reminders IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        foreach ($candidateIds as $taskId) {
            $em = $this->doctrine->getManager();
            $task = $em->find(Task::class, $taskId);
            if (!$task instanceof Task) {
                continue;
            }

            try {
                [$c, $e, $p] = $this->processTask($em, $task, $now);
                $created += $c;
                $emailsSent += $e;
                $pushesSent += $p;
            } catch (UniqueConstraintViolationException) {
                // A concurrent dispatcher won the race on one of this task's
                // rows. The failed flush closes the EntityManager (Doctrine
                // ORM 3), so we reset the manager and move on — the other run
                // owns the rest of this task's reminders, and any genuinely
                // missed row is picked up on the next pass.
                $this->doctrine->resetManager();
            }
        }

        return new TaskReminderDispatchResult($created, $emailsSent, $pushesSent);
    }

    /**
     * Creates the due reminder notifications for a single task and fires their
     * side-effects. Lets a {@see UniqueConstraintViolationException} (which
     * closes the EM) propagate when a concurrent dispatcher already inserted
     * one of the rows; the caller resets the manager and continues.
     *
     * @return array{int, int, int} [created, emailsSent, pushesSent]
     */
    private function processTask(ObjectManager $em, Task $task, \DateTimeImmutable $now): array
    {
        $reminders = $task->getReminders() ?? [];
        if ([] === $reminders) {
            return [0, 0, 0];
        }
        $recipients = $this->collectRecipients($task);
        if ([] === $recipients) {
            return [0, 0, 0];
        }

        $notifications = $this->doctrine->getRepository(Notification::class);
        $created = 0;
        $emailsSent = 0;
        $pushesSent = 0;

        $dueDate = $task->getDueDate();
        foreach ($reminders as $reminder) {
            if (!is_array($reminder)) {
                continue;
            }
            foreach ($this->scheduler->dueFires($reminder, $dueDate, $now) as $fire) {
                $key = $fire['key'];
                $label = $this->scheduler->describe($reminder);

                foreach ($recipients as $recipient) {
                    if ($notifications->taskReminderExists($recipient, $task, $key)) {
                        continue;
                    }

                    $notification = new Notification();
                    $notification->setRecipient($recipient);
                    $notification->setTask($task);
                    $notification->setReminderOffset($key);
                    $notification->setType(Notification::TYPE_TASK_REMINDER);
                    $notification->setTitle(sprintf('Reminder: %s', $task->getTitle()));
                    $notification->setBody($this->buildBody($task, $label));
                    $em->persist($notification);
                    $em->flush();
                    ++$created;

                    if ($this->sendReminderEmail($recipient, $task, $label)) {
                        ++$emailsSent;
                    }
                    $pushesSent += $this->sendReminderPush($em, $recipient, $task, $key, $label);
                }
            }
        }

        return [$created, $emailsSent, $pushesSent];
    }

    /**
     * Sends a Web Push for the reminder to every subscription the recipient
     * has registered, gated on `pushNotificationsEnabled`. Subscriptions the
     * push service reports as expired (404/410) are pruned inline.
     *
     * Returns the count of pushes the transport accepted.
     */
    private function sendReminderPush(ObjectManager $em, User $recipient, Task $task, string $key, string $label): int
    {
        $prefs = $recipient->getPreferences();
        if (true !== ($prefs['pushNotificationsEnabled'] ?? false)) {
            return 0;
        }

        $subscriptions = $this->doctrine->getRepository(PushSubscription::class)->findActiveForUser($recipient);
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
                $em->remove($subscription);
                $em->flush();
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
