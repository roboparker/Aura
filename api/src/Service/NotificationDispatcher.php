<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Discussion;
use App\Entity\Notification;
use App\Entity\Page;
use App\Entity\Task;
use App\Entity\User;
use App\Push\PushPayload;
use App\Push\PushSenderInterface;
use App\Repository\NotificationRepository;
use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Single creation path for in-app notifications (mentions, replies,
 * comments, assignments, status changes). Centralises the rules every
 * producer needs: never notify the actor about their own action, honour
 * the one-row-per-comment precedence (mention > reply > comment), and
 * fan the row out to the live bell (Mercure), Web Push, and a real-time
 * email.
 *
 * Email mirrors the reminder dispatcher's split: a real-time email goes
 * out here only when the recipient is on the `realtime` frequency;
 * digest-frequency users instead have their rows rolled up by
 * App\Command\DispatchNotificationDigestCommand (which only selects
 * digest-cadence users), so no one is double-emailed.
 *
 * The send itself is non-blocking: Symfony Mailer's SendEmailMessage is
 * routed to the async (Doctrine) transport in messenger.yaml, so
 * $mailer->send() enqueues a job and the messenger worker does the actual
 * SMTP delivery (with retries + dead-letter). SMTP latency never touches
 * the producing request.
 */
final class NotificationDispatcher
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationRepository $notifications,
        private NotificationMercurePublisher $mercure,
        private PushSenderInterface $pushSender,
        private PushSubscriptionRepository $pushSubscriptions,
        private MailerInterface $mailer,
        private QuietHours $quietHours,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private ?string $mailerFrom = null,
    ) {
    }

    /** Notification type → per-event matrix row. */
    private const TYPE_TO_ROW = [
        Notification::TYPE_MENTION => 'mentions',
        Notification::TYPE_REPLY => 'replies',
        Notification::TYPE_COMMENT => 'comments',
        Notification::TYPE_ASSIGNED => 'assigned',
        Notification::TYPE_STATUS => 'status',
    ];

    /**
     * Creates and fans out one notification. Returns the row, or null
     * when it was skipped (self-notification, or a higher-precedence
     * comment notification already exists for the recipient).
     */
    public function notify(
        User $recipient,
        string $type,
        ?User $actor,
        string $title,
        ?string $body = null,
        ?Task $task = null,
        ?Comment $comment = null,
        ?Discussion $discussion = null,
        ?Page $page = null,
    ): ?Notification {
        // No self-notifications — you don't get pinged for your own act.
        if (
            null !== $actor
            && null !== $actor->getId()
            && true === $actor->getId()->equals($recipient->getId())
        ) {
            return null;
        }

        // One comment-derived row per recipient; callers dispatch in
        // precedence order (mention first) so the lower-precedence types
        // no-op here once a row exists.
        if (null !== $comment && $this->notifications->commentNotificationExists($recipient, $comment)) {
            return null;
        }

        $notification = new Notification();
        $notification->setRecipient($recipient);
        $notification->setActor($actor);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setBody($body);
        $notification->setTask($task);
        $notification->setComment($comment);
        $notification->setDiscussion($discussion);
        $notification->setPage($page);

        $this->em->persist($notification);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent producer on the same
            // (recipient, comment) key — recover and treat as deduped.
            $this->em->clear();

            return null;
        }

        // In-app row is the inbox source of truth and always persists. The
        // live bell + push respect the per-event "in-app" toggle; email
        // respects the "email" toggle (in sendEmail). Quiet hours suppress
        // the disturbing channels (email + push) but not the bell.
        $inAppAllowed = $this->matrixAllows($recipient, $notification->getType(), 'inApp');
        $quiet = $this->quietHours->isQuiet($recipient);

        if ($inAppAllowed) {
            $this->mercure->publishCreated($notification);
            if (!$quiet) {
                $this->sendPush($recipient, $notification);
            }
        }
        if (!$quiet) {
            $this->sendEmail($recipient, $notification);
        }

        return $notification;
    }

    /**
     * Whether the recipient's per-event matrix permits `$channel`
     * ('inApp'|'email') for a notification `$type`. Types without a matrix
     * row (e.g. reminders, which don't flow through here) default to allowed.
     */
    private function matrixAllows(User $recipient, string $type, string $channel): bool
    {
        $row = self::TYPE_TO_ROW[$type] ?? null;
        if (null === $row) {
            return true;
        }
        $matrix = $recipient->getPreferences()['notificationMatrix'] ?? null;
        $config = is_array($matrix) && is_array($matrix[$row] ?? null) ? $matrix[$row] : null;
        if (null === $config) {
            return true;
        }
        return true === ($config[$channel] ?? true);
    }

    /**
     * Web Push fan-out, gated on the recipient's `pushNotificationsEnabled`
     * preference. Expired subscriptions (browser revoked / GC'd) are
     * pruned inline so the next dispatch doesn't re-attempt a dead
     * endpoint — same handling as the reminder dispatcher.
     */
    private function sendPush(User $recipient, Notification $notification): void
    {
        $prefs = $recipient->getPreferences();
        if (true !== ($prefs['pushNotificationsEnabled'] ?? false)) {
            return;
        }

        $subscriptions = $this->pushSubscriptions->findActiveForUser($recipient);
        if ([] === $subscriptions) {
            return;
        }

        $target = $notification->getTargetUrl();
        $payload = new PushPayload(
            title: $notification->getTitle(),
            body: $notification->getBody() ?? '',
            url: null === $target ? rtrim($this->frontendUrl, '/') : rtrim($this->frontendUrl, '/') . $target,
            tag: 'notification-' . $notification->getId(),
        );

        foreach ($subscriptions as $subscription) {
            $result = $this->pushSender->send($subscription, $payload);
            if ($result->subscriptionExpired) {
                $this->em->remove($subscription);
                $this->em->flush();
                continue;
            }
        }
    }

    /**
     * Real-time email, gated on the recipient's `emailNotificationsEnabled`
     * (master switch, defaults on) and `notificationFrequency === 'realtime'`.
     * Digest-cadence users are skipped here — the digest command owns their
     * delivery.
     *
     * `$mailer->send()` doesn't hit SMTP inline: SendEmailMessage is routed
     * to the async transport, so this enqueues the delivery and the worker
     * does the send (with retries / dead-letter on transport failure).
     */
    private function sendEmail(User $recipient, Notification $notification): void
    {
        $prefs = $recipient->getPreferences();
        // Master switch wins over the matrix.
        if (false === ($prefs['emailNotificationsEnabled'] ?? true)) {
            return;
        }
        // Per-event email toggle.
        if (!$this->matrixAllows($recipient, $notification->getType(), 'email')) {
            return;
        }
        // Only realtime-cadence users get a per-event email here; digest
        // users are rolled up by DispatchNotificationDigestCommand. The UI
        // keeps notificationFrequency in sync with emailDigest.mode, so this
        // stays the canonical cadence field.
        if ('realtime' !== ($prefs['notificationFrequency'] ?? 'realtime')) {
            return;
        }

        $base = rtrim($this->frontendUrl, '/');
        $target = $notification->getTargetUrl();

        $email = (new TemplatedEmail())
            ->from((null !== $this->mailerFrom && '' !== $this->mailerFrom) ? $this->mailerFrom : 'no-reply@aura.test')
            ->to($recipient->getEmail())
            ->subject($notification->getTitle())
            ->htmlTemplate('emails/notification.html.twig')
            ->textTemplate('emails/notification.txt.twig')
            ->context([
                'recipient' => $recipient,
                'notification' => $notification,
                'actionUrl' => null === $target ? $base . '/notifications' : $base . $target,
            ]);

        $this->mailer->send($email);
    }
}
