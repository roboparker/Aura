<?php

namespace App\Mcp\Tool;

use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class UnassignTaskTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'unassign_task';
    }

    public function getDescription(): string
    {
        return 'Remove one user from a task\'s assignee set. Pass userId to drop a specific person, or omit it to clear the entire list.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string'],
                'userId' => ['type' => 'string', 'description' => 'Optional — when omitted, clears all assignees.'],
            ],
            'required' => ['taskId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $taskId = $this->input->requireUuid('taskId', $arguments['taskId'] ?? null);
        $task = $this->em->getRepository(Task::class)->find($taskId);
        if (null === $task || !$this->authz->canEditTask($task, $user)) {
            throw McpException::notFound(sprintf('Task %s', $taskId));
        }

        $userId = $this->input->optionalUuid('userId', $arguments['userId'] ?? null);
        if (null === $userId) {
            $task->getAssignees()->clear();
        } else {
            $assignee = $this->em->getRepository(User::class)->find($userId);
            if (null !== $assignee) {
                $task->removeAssignee($assignee);
            }
        }

        $this->em->flush();
        return $this->serializer->task($task);
    }
}
