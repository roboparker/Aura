<?php

namespace App\Service;

use App\Entity\Notification;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Publishes new notifications on the recipient's private Mercure topic
 * (`/users/{id}/notifications`) so the bell updates live instead of
 * polling. Mirrors {@see CommentMercurePublisher}: updates are
 * `private=true`, so only a subscriber whose JWT lists the topic
 * receives them — and {@see App\Controller\NotificationsMercureTokenController}
 * only ever mints a JWT for the caller's own topic.
 */
final class NotificationMercurePublisher
{
    public function __construct(
        private HubInterface $hub,
        private NormalizerInterface $normalizer,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function topicFor(string $recipientId): string
    {
        return '/users/' . $recipientId . '/notifications';
    }

    public function publishCreated(Notification $notification): void
    {
        $recipient = $notification->getRecipient();
        $recipientId = $recipient?->getId();
        if (null === $recipientId) {
            return;
        }

        /** @var array<string, mixed> $serialized */
        $serialized = $this->normalizer->normalize($notification, 'jsonld', ['groups' => ['notification:read']]);

        $this->dispatch(self::topicFor((string) $recipientId), [
            'type' => 'create',
            'notification' => $serialized,
        ]);
    }

    /**
     * Best-effort publish — Mercure is a side channel, so a hub blip must
     * not drag down the write that produced the notification. Same
     * trade-off as the comment publisher.
     *
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $topic, array $payload): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($payload, \JSON_THROW_ON_ERROR), true));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure notification publish failed; the bell will catch up on next poll.', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
