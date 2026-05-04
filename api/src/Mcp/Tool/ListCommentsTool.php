<?php

namespace App\Mcp\Tool;

use App\Entity\Comment;
use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class ListCommentsTool implements McpToolInterface
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
        return 'list_comments';
    }

    public function getDescription(): string
    {
        return 'List comments on a task in chronological order, paginated. Replies are included as flat entries with parentCommentId set so the caller can reconstruct the tree.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'taskId' => ['type' => 'string'],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
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

        ['page' => $page, 'limit' => $limit] = $this->input->pagination($arguments);

        $qb = $this->em->getRepository(Comment::class)
            ->createQueryBuilder('c')
            ->where('c.task = :task')
            ->setParameter('task', $task)
            ->orderBy('c.createdAt', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $items = [];
        foreach ($paginator as $comment) {
            /** @var Comment $comment */
            $items[] = $this->serializer->comment($comment);
        }

        return [
            'items' => $items,
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
