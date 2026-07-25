<?php

namespace App\Mcp\Tool;

use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Repository\TaskRelationshipRepository;
use Doctrine\ORM\EntityManagerInterface;

final class GetTaskTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
        private TaskRelationshipRepository $relationships,
    ) {
    }

    public function getName(): string
    {
        return 'get_task';
    }

    public function getDescription(): string
    {
        return 'Fetch one task by id, including assignees, tags, board, attachments, and its subtask rollup. Returns 404 when the task is not visible to the caller.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string', 'description' => 'UUID of the task.'],
            ],
            'required' => ['taskId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $taskId = $this->input->requireUuid('taskId', $arguments['taskId'] ?? null);
        $task = $this->em->getRepository(Task::class)->find($taskId);
        if (null === $task || !$this->authz->canReadTask($task, $user)) {
            throw McpException::notFound(sprintf('Task %s', $taskId));
        }
        $progress = $this->relationships->subtaskProgressFor([(string) $task->getId()]);

        return $this->serializer->task($task, $progress[(string) $task->getId()] ?? null);
    }
}
