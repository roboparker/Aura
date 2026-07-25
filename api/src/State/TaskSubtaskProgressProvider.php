<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Task;
use App\Repository\TaskRelationshipRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Attaches each task's subtask rollup (`{total, completed}`) to a collection
 * read, so task rows can show a "2/5" badge without the client asking per task.
 *
 * Decorates {@see TaskSearchProvenanceProvider} (which in turn decorates the
 * stock collection provider), so access scoping, filters, pagination *and*
 * search provenance are all untouched — this only adds one batched query over
 * the ids already on the page.
 *
 * Collection-only on purpose: the task drawer, the one place that shows the
 * full subtask list, already calls `GET /tasks/{id}/subtasks` for the rows
 * themselves, so adding a rollup query to every item read would buy nothing.
 *
 * @implements ProviderInterface<Task>
 */
final class TaskSubtaskProgressProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<Task> $inner
     */
    public function __construct(
        #[Autowire(service: TaskSearchProvenanceProvider::class)]
        private ProviderInterface $inner,
        private TaskRelationshipRepository $relationships,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $data = $this->inner->provide($operation, $uriVariables, $context);

        if (!is_iterable($data)) {
            return $data;
        }

        /** @var list<Task> $tasks */
        $tasks = [];
        foreach ($data as $task) {
            $tasks[] = $task;
        }
        if ([] === $tasks) {
            return $data;
        }

        $ids = [];
        foreach ($tasks as $task) {
            $id = $task->getId();
            if (null !== $id) {
                $ids[] = (string) $id;
            }
        }

        $progress = $this->relationships->subtaskProgressFor($ids);
        foreach ($tasks as $task) {
            $key = (string) $task->getId();
            // Absent from the rollup = no children; the entity's zeroed default
            // already says that, so only set what we found.
            if (isset($progress[$key])) {
                $task->setSubtaskProgress($progress[$key]);
            }
        }

        return $data;
    }
}
