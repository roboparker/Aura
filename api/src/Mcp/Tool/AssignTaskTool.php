<?php

namespace App\Mcp\Tool;

use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class AssignTaskTool implements McpToolInterface
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
        return 'assign_task';
    }

    public function getDescription(): string
    {
        return 'Add an assignee to a task without disturbing the existing list. Assignee must be the task owner or, for board tasks, a member of the board — same rule the PWA enforces.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string'],
                'userId' => ['type' => 'string'],
            ],
            'required' => ['taskId', 'userId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $taskId = $this->input->requireUuid('taskId', $arguments['taskId'] ?? null);
        $userId = $this->input->requireUuid('userId', $arguments['userId'] ?? null);

        $task = $this->em->getRepository(Task::class)->find($taskId);
        if (null === $task || !$this->authz->canEditTask($task, $user)) {
            throw McpException::notFound(sprintf('Task %s', $taskId));
        }
        $assignee = $this->em->getRepository(User::class)->find($userId);
        if (null === $assignee) {
            throw McpException::notFound(sprintf('User %s', $userId));
        }

        $task->addAssignee($assignee);
        $this->input->assertValid($task);
        $this->em->flush();

        return $this->serializer->task($task);
    }
}
