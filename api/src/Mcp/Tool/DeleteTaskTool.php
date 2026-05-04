<?php

namespace App\Mcp\Tool;

use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class DeleteTaskTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'delete_task';
    }

    public function getDescription(): string
    {
        return 'Permanently delete a task. Returns 404 when the task is not visible; the same delete permissions as the PWA apply (owner or project member).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string', 'description' => 'UUID of the task to delete.'],
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
        $this->em->remove($task);
        $this->em->flush();
        return ['deleted' => true, 'taskId' => (string) $taskId];
    }
}
