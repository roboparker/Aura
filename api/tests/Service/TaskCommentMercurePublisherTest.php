<?php

namespace App\Tests\Service;

use App\Entity\TaskComment;
use App\Entity\Task;
use App\Entity\User;
use App\Service\TaskCommentMercurePublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Unit tests for the publisher. The hub is replaced with an in-memory
 * recorder; we don't go through ApiPlatform here because the goal is to
 * pin the topic format and payload shape so subscribers can rely on them.
 */
class TaskCommentMercurePublisherTest extends TestCase
{
    public function testPublishCreatedSendsCommentPayloadOnTaskTopic(): void
    {
        [$publisher, $hub] = $this->makePublisher();

        $publisher->publishCreated($this->makeComment(
            taskId: '0193aaaa-0001-7000-8000-000000000001',
            commentId: '0193bbbb-0001-7000-8000-000000000001',
            body: 'Looks good.',
        ));

        $this->assertCount(1, $hub->updates);
        $update = $hub->updates[0];
        $this->assertSame('/tasks/0193aaaa-0001-7000-8000-000000000001/comments', $update->getTopics()[0]);
        $this->assertTrue($update->isPrivate(), 'Updates must be private so unauthorised subscribers cannot read them.');

        $payload = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('create', $payload['type']);
        $this->assertSame('Looks good.', $payload['comment']['body']);
        $this->assertSame('/task_comments/0193bbbb-0001-7000-8000-000000000001', $payload['comment']['@id']);
    }

    public function testPublishUpdatedUsesUpdateType(): void
    {
        [$publisher, $hub] = $this->makePublisher();

        $publisher->publishUpdated($this->makeComment(
            taskId: '0193aaaa-0001-7000-8000-000000000002',
            commentId: '0193bbbb-0001-7000-8000-000000000002',
            body: 'Edited.',
        ));

        $payload = json_decode($hub->updates[0]->getData(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('update', $payload['type']);
        $this->assertSame('Edited.', $payload['comment']['body']);
    }

    public function testPublishDeletedSendsLeanIriPayload(): void
    {
        [$publisher, $hub] = $this->makePublisher();

        $publisher->publishDeleted($this->makeComment(
            taskId: '0193aaaa-0001-7000-8000-000000000003',
            commentId: '0193bbbb-0001-7000-8000-000000000003',
            body: 'Stale comment.',
        ));

        $update = $hub->updates[0];
        $this->assertSame(
            '/tasks/0193aaaa-0001-7000-8000-000000000003/comments',
            $update->getTopics()[0],
        );
        $payload = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame([
            'type' => 'delete',
            'id' => '/task_comments/0193bbbb-0001-7000-8000-000000000003',
            'task' => '/tasks/0193aaaa-0001-7000-8000-000000000003',
        ], $payload);
    }

    public function testPublishCreatedNoOpWhenTaskMissing(): void
    {
        // Defensive guard for entities that somehow lose their task
        // reference between persist and publish — we'd rather skip the
        // event than throw and 500 the API call.
        [$publisher, $hub] = $this->makePublisher();
        $comment = new TaskComment();
        $publisher->publishCreated($comment);
        $this->assertCount(0, $hub->updates);
    }

    /**
     * @return array{0: TaskCommentMercurePublisher, 1: object{updates: array<int, Update>}}
     */
    private function makePublisher(): array
    {
        $hub = new class implements HubInterface {
            /** @var array<int, Update> */
            public array $updates = [];

            public function publish(Update $update): string
            {
                $this->updates[] = $update;
                return 'id-' . count($this->updates);
            }

            public function getUrl(): string { return 'http://test'; }
            public function getPublicUrl(): string { return 'http://test'; }
            public function getProvider(): \Symfony\Component\Mercure\Jwt\TokenProviderInterface { throw new \LogicException(); }
            public function getFactory(): ?\Symfony\Component\Mercure\Jwt\TokenFactoryInterface { return null; }
        };

        $normalizer = $this->createMock(NormalizerInterface::class);
        $normalizer->method('normalize')->willReturnCallback(
            // Inline serialiser keeps the test independent of the API
            // Platform serializer setup; the important assertion is that
            // the publisher routes data through whatever NormalizerInterface
            // it gets — the actual JSON-LD shape is exercised by the
            // existing CommentTest cases.
            static function (TaskComment $c): array {
                return [
                    '@id' => '/task_comments/' . $c->getId(),
                    '@type' => 'TaskComment',
                    'id' => (string) $c->getId(),
                    'body' => $c->getBody(),
                ];
            },
        );

        return [new TaskCommentMercurePublisher($hub, $normalizer), $hub];
    }

    private function makeComment(string $taskId, string $commentId, string $body): TaskComment
    {
        $owner = new User();
        $this->setEntityId($owner, '0193cccc-0001-7000-8000-000000000001');

        $task = new Task();
        $task->setOwner($owner);
        $task->setTitle('Parent task');
        $this->setEntityId($task, $taskId);

        $comment = new TaskComment();
        $comment->setTask($task);
        $comment->setAuthor($owner);
        $comment->setBody($body);
        $this->setEntityId($comment, $commentId);

        return $comment;
    }

    private function setEntityId(object $entity, string $uuid): void
    {
        $reflection = new \ReflectionClass($entity);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($entity, Uuid::fromString($uuid));
    }
}
