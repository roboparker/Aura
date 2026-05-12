<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TaskComment;
use App\Service\TaskCommentMentionService;
use App\Service\TaskCommentMercurePublisher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wraps the default ORM persist processor on `PATCH /task_comments/{id}` so we
 * can publish the edit to Mercure subscribers after the row has been
 * flushed. Same pattern as TaskUpdateProcessor.
 *
 * @implements ProcessorInterface<TaskComment, TaskComment>
 */
final class TaskCommentUpdateProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TaskComment, TaskComment> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private TaskCommentMercurePublisher $publisher,
        private TaskCommentMentionService $mentions,
    ) {
    }

    /**
     * @param TaskComment $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TaskComment
    {
        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        // Edits can introduce new @mentions; the (recipient, comment)
        // unique key + alreadyNotified short-circuit keep prior mentions
        // from re-firing.
        $this->mentions->dispatchMentions($result);
        $this->publisher->publishUpdated($result);
        return $result;
    }
}
