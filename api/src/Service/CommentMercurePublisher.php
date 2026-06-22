<?php

namespace App\Service;

use App\Entity\Comment;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Publishes comment lifecycle events on the parent's Mercure topic so
 * collaborators see each other's posts without reloading. The topic
 * is the parent's path with `/comments` appended (e.g.
 * `/tasks/abc-123/comments`, `/pages/abc-123/comments`, or
 * `/discussions/abc-123/comments`), which matches what the
 * subscribe-token endpoint authorizes.
 *
 * Updates are published with `private=true` — only subscribers whose
 * JWT lists the topic in its `subscribe` claim will receive them. The
 * `CommentsMercureTokenController` is the only place that mints those
 * JWTs, and it re-uses the parent's GET security expression, so no
 * one downstream of this service can subscribe without proving they
 * can read the parent.
 */
final class CommentMercurePublisher
{
    public function __construct(
        private HubInterface $hub,
        private NormalizerInterface $normalizer,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function publishCreated(Comment $comment): void
    {
        $this->publish('create', $comment, $this->serializeComment($comment));
    }

    public function publishUpdated(Comment $comment): void
    {
        $this->publish('update', $comment, $this->serializeComment($comment));
    }

    /**
     * Delete is special: by the time a subscriber would want to fetch
     * the comment it's already gone, so we send just the IRI + parent
     * IRI. The frontend uses the IRI to drop the row from local state.
     */
    public function publishDeleted(Comment $comment): void
    {
        $topic = $this->topicFor($comment);
        if (null === $topic || null === $comment->getId()) {
            return;
        }
        $payload = [
            'type' => 'delete',
            'id' => '/comments/' . $comment->getId(),
            'parent' => $this->parentIri($comment),
        ];
        $this->dispatch($topic, $payload);
    }

    /**
     * @param array<string, mixed> $serialized
     */
    private function publish(string $type, Comment $comment, array $serialized): void
    {
        $topic = $this->topicFor($comment);
        if (null === $topic) {
            return;
        }
        $this->dispatch($topic, [
            'type' => $type,
            'comment' => $serialized,
        ]);
    }

    private function topicFor(Comment $comment): ?string
    {
        $parent = $this->parentIri($comment);
        return null === $parent ? null : $parent . '/comments';
    }

    private function parentIri(Comment $comment): ?string
    {
        $task = $comment->getTask();
        if (null !== $task && null !== $task->getId()) {
            return '/tasks/' . $task->getId();
        }
        $page = $comment->getPage();
        if (null !== $page && null !== $page->getId()) {
            return '/pages/' . $page->getId();
        }
        $discussion = $comment->getDiscussion();
        if (null !== $discussion && null !== $discussion->getId()) {
            return '/discussions/' . $discussion->getId();
        }
        $feedback = $comment->getFeedback();
        if (null !== $feedback && null !== $feedback->getId()) {
            return '/feedback/' . $feedback->getId();
        }
        return null;
    }

    /**
     * Best-effort publish. Mercure is a notification side-channel —
     * if the hub is briefly unreachable (CI cold-start, network
     * blip, hub restart), swallow the error and log so the caller's
     * write isn't dragged into a 500. The cost is "a live subscriber
     * may miss this event until they reload," which is the right
     * trade-off vs. failing a comment POST because the hub is
     * warming up.
     *
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $topic, array $payload): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($payload, \JSON_THROW_ON_ERROR), true));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed; subscribers may miss this event.', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeComment(Comment $comment): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($comment, 'jsonld', ['groups' => ['comment:read']]);
        return $data;
    }
}
