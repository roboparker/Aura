<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Task;
use App\Entity\TaskRelationship;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Repository\TaskRelationshipRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lists a task's relationships from that task's viewpoint — each with a
 * direction-aware label ("parent of" vs "child of") and a summary of the other
 * task. Mirrors `GET /tasks/{id}/relationships`; useful before unlink_tasks so
 * the model knows exactly which links exist.
 */
final class ListTaskRelationshipsTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private TaskRelationshipRepository $relationships,
        private McpAuthorization $authz,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'list_task_relationships';
    }

    public function getDescription(): string
    {
        return 'List a task\'s relationships (subtasks, parents, dependencies, links) with direction-aware labels and the other task\'s summary. Call before unlink_tasks to see what exists.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string', 'description' => 'UUID of the task whose relationships to list.'],
            ],
            'required' => ['taskId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $taskId = $this->input->requireUuid('taskId', $this->input->requireString($arguments, 'taskId'));

        $task = $this->em->getRepository(Task::class)->find($taskId);
        if (null === $task || !$this->authz->canReadTask($task, $user)) {
            throw McpException::notFound(sprintf('Task %s', $taskId));
        }

        $taskIdStr = (string) $task->getId();
        $items = [];
        foreach ($this->relationships->findForTask($task) as $relationship) {
            $source = $relationship->getSource();
            $target = $relationship->getTarget();
            if (null === $source || null === $target) {
                continue;
            }
            $outgoing = (string) $source->getId() === $taskIdStr;
            $other = $outgoing ? $target : $source;

            $items[] = [
                'type' => $relationship->getType(),
                'direction' => $outgoing ? 'outgoing' : 'incoming',
                'label' => TaskRelationship::labelFor($relationship->getType(), $outgoing),
                'otherTask' => [
                    'id' => (string) $other->getId(),
                    'title' => $other->getTitle(),
                    'completed' => null !== $other->getCompletedOn(),
                    'dueDate' => $other->getDueDate()?->format(\DateTimeInterface::ATOM),
                ],
            ];
        }

        return ['items' => $items];
    }
}
