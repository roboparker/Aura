<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TaskComment;
use App\Service\TaskCommentMercurePublisher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wraps the default ORM remove processor on `DELETE /task_comments/{id}`.
 * The publish has to happen *before* the SQL delete so the entity still
 * has its id and parent task — after `process()` returns the in-memory
 * object's id is gone and the doctrine listener has already detached it.
 *
 * @implements ProcessorInterface<TaskComment, void|TaskComment>
 */
final class TaskCommentDeleteProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TaskComment, void|TaskComment> $removeProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private TaskCommentMercurePublisher $publisher,
    ) {
    }

    /**
     * @param TaskComment $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // Snapshot first; the remove processor wipes the in-memory id.
        $this->publisher->publishDeleted($data);
        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
