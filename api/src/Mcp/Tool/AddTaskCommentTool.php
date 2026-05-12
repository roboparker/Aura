<?php

namespace App\Mcp\Tool;

use App\Entity\TaskComment;
use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class AddTaskCommentTool implements McpToolInterface
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
        return 'add_task_comment';
    }

    public function getDescription(): string
    {
        return 'Add a Markdown comment to a task. Optionally pass parentCommentId to reply to an existing comment in the same task.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string'],
                'body' => ['type' => 'string', 'description' => 'Markdown body (max 10000 chars).'],
                'parentCommentId' => ['type' => 'string', 'description' => 'Optional reply target. Must belong to the same task.'],
            ],
            'required' => ['taskId', 'body'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $taskId = $this->input->requireUuid('taskId', $arguments['taskId'] ?? null);
        $body = $this->input->requireString($arguments, 'body');

        $task = $this->em->getRepository(Task::class)->find($taskId);
        if (null === $task || !$this->authz->canReadTask($task, $user)) {
            throw McpException::notFound(sprintf('Task %s', $taskId));
        }

        $comment = new TaskComment();
        $comment->setTask($task);
        $comment->setAuthor($user);
        $comment->setBody($body);

        if (null !== $parentId = $this->input->optionalUuid('parentCommentId', $arguments['parentCommentId'] ?? null)) {
            $parent = $this->em->getRepository(TaskComment::class)->find($parentId);
            if (null === $parent) {
                throw McpException::notFound(sprintf('Parent comment %s', $parentId));
            }
            $comment->setParentComment($parent);
        }

        $this->input->assertValid($comment);
        $this->em->persist($comment);
        $this->em->flush();

        return $this->serializer->comment($comment);
    }
}
