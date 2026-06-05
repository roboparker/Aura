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
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Single creation path for in-app notifications (mentions, replies,
 * comments, assignments, status changes). Centralises the rules every
 * producer needs: never notify the actor about their own action, honour
 * the one-row-per-comment precedence (mention > reply > comment), and
 * fan the row out to the live bell (Mercure) + Web Push.
 *
 * Realtime/digest email is intentionally NOT sent here: rows created by
 * the dispatcher are picked up by the existing digest command for users
 * on a digest frequency, and realtime users already get the live bell +
 * push. (Per-event realtime email for these types is a follow-up.)
 */
final class NotificationDispatcher
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationRepository $notifications,
        private NotificationMercurePublisher $mercure,
        private PushSenderInterface $pushSender,
        private PushSubscriptionRepository $pushSubscriptions,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

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

        $this->mercure->publishCreated($notification);
        $this->sendPush($recipient, $notification);

        return $notification;
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
}
