<?php

namespace App\Mcp\Tool;

use App\Entity\Comment;
use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Service\CommentMentionService;
use Doctrine\ORM\EntityManagerInterface;

final class AddTaskCommentTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
        private CommentMentionService $mentions,
    ) {
    }

    public function getName(): string
    {
        return 'add_task_comment';
    }

    public function getDescription(): string
    {
        return 'Add a Markdown comment to a task. Threads are flat — every comment is a top-level entry, ordered chronologically.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string'],
                'body' => ['type' => 'string', 'description' => 'Markdown body (max 50000 chars).'],
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

        $comment = new Comment();
        $comment->setTask($task);
        $comment->setAuthor($user);
        $comment->setBody($body);

        $this->input->assertValid($comment);
        $this->em->persist($comment);
        $this->em->flush();

        // `@mention` tokens fire notifications post-persist — same
        // path as the HTTP `POST /comments` flow.
        $this->mentions->dispatchMentions($comment);

        return $this->serializer->comment($comment);
    }
}
