<?php

namespace App\Service;

use App\Entity\TaskComment;
use App\Entity\Task;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Publishes comment lifecycle events on the parent task's Mercure topic so
 * collaborators see each other's posts without reloading. The topic is the
 * task's path with `/comments` appended (e.g. `/tasks/abc-123/comments`),
 * which matches what the subscribe-token endpoint authorizes.
 *
 * Updates are published with `private=true` — only subscribers whose JWT
 * lists the topic in its `subscribe` claim will receive them. The
 * `TaskCommentsMercureTokenController` is the only place that mints those
 * JWTs, and it re-uses the Task GET security expression, so no one
 * downstream of this service can subscribe without proving they can read
 * the parent task.
 */
final class TaskCommentMercurePublisher
{
    public function __construct(
        private HubInterface $hub,
        private NormalizerInterface $normalizer,
    ) {
    }

    public function publishCreated(TaskComment $comment): void
    {
        $this->publish('create', $comment, $this->serializeComment($comment));
    }

    public function publishUpdated(TaskComment $comment): void
    {
        $this->publish('update', $comment, $this->serializeComment($comment));
    }

    /**
     * Delete is special: by the time a subscriber would want to fetch the
     * comment it's already gone, so we send just the IRI + parent task IRI.
     * The frontend uses the IRI to drop the row from local state.
     */
    public function publishDeleted(TaskComment $comment): void
    {
        $task = $comment->getTask();
        if (null === $task || null === $task->getId() || null === $comment->getId()) {
            return;
        }
        $payload = [
            'type' => 'delete',
            'id' => '/task_comments/' . $comment->getId(),
            'task' => '/tasks/' . $task->getId(),
        ];
        $this->dispatch($task, $payload);
    }

    /**
     * @param array<string, mixed> $serialized
     */
    private function publish(string $type, TaskComment $comment, array $serialized): void
    {
        $task = $comment->getTask();
        if (null === $task || null === $task->getId()) {
            return;
        }
        $this->dispatch($task, [
            'type' => $type,
            'comment' => $serialized,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(Task $task, array $payload): void
    {
        $topic = '/tasks/' . $task->getId() . '/comments';
        $this->hub->publish(new Update($topic, json_encode($payload, \JSON_THROW_ON_ERROR), true));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeComment(TaskComment $comment): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($comment, 'jsonld', ['groups' => ['task_comment:read']]);
        return $data;
    }
}
