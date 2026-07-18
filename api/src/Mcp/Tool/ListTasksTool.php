<?php

namespace App\Mcp\Tool;

use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Mcp\TaskRelationWarmer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class ListTasksTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
        private TaskRelationWarmer $warmer,
    ) {
    }

    public function getName(): string
    {
        return 'list_tasks';
    }

    public function getDescription(): string
    {
        return 'List tasks visible to the user with optional filters (board, assignee, status, due-before/after, tags, search). Paginated; returns the slice plus a total count so callers can iterate.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'boardId' => ['type' => 'string'],
                'assigneeId' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['open', 'completed']],
                'dueBefore' => ['type' => 'string', 'description' => 'ISO-8601 datetime — include only tasks due before this.'],
                'dueAfter' => ['type' => 'string', 'description' => 'ISO-8601 datetime — include only tasks due after this.'],
                'tagIds' => ['type' => 'array', 'items' => ['type' => 'string']],
                'search' => ['type' => 'string', 'description' => 'Free-text query against title, description, and comments.'],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $pagination = $this->input->pagination($arguments);
        $page = $pagination['page'];
        $limit = $pagination['limit'];

        $qb = $this->buildBaseQuery($user);

        if (null !== $boardId = $this->input->optionalUuid('boardId', $arguments['boardId'] ?? null)) {
            $qb->andWhere('t.board = :boardId')->setParameter('boardId', $boardId);
        }
        if (null !== $assigneeId = $this->input->optionalUuid('assigneeId', $arguments['assigneeId'] ?? null)) {
            $qb->innerJoin('t.assignees', 'a_filter', 'WITH', 'a_filter.id = :assigneeId')
                ->setParameter('assigneeId', $assigneeId);
        }
        if (null !== $status = $this->input->optionalString($arguments, 'status')) {
            if ('open' === $status) {
                $qb->andWhere('t.completedOn IS NULL');
            } elseif ('completed' === $status) {
                $qb->andWhere('t.completedOn IS NOT NULL');
            } else {
                throw McpException::invalidInput('status must be "open" or "completed".');
            }
        }
        if (null !== $dueBefore = $this->input->optionalDateTime($arguments, 'dueBefore')) {
            $qb->andWhere('t.dueDate < :dueBefore')->setParameter('dueBefore', $dueBefore);
        }
        if (null !== $dueAfter = $this->input->optionalDateTime($arguments, 'dueAfter')) {
            $qb->andWhere('t.dueDate > :dueAfter')->setParameter('dueAfter', $dueAfter);
        }

        $tagIds = $this->input->optionalStringArray($arguments, 'tagIds');
        if (null !== $tagIds && [] !== $tagIds) {
            $tagUuids = array_map(fn ($id) => $this->input->requireUuid('tagIds[]', $id), $tagIds);
            $qb->innerJoin('t.tags', 'tag_filter', 'WITH', 'tag_filter.id IN (:tagIds)')
                ->setParameter('tagIds', $tagUuids);
        }

        $search = $this->input->optionalString($arguments, 'search');
        if (null !== $search && '' !== trim($search)) {
            $qb->leftJoin(\App\Entity\Comment::class, 'c_search', 'WITH', 'c_search.task = t')
                ->andWhere('LOWER(t.title) LIKE :search OR LOWER(t.description) LIKE :search OR LOWER(c_search.body) LIKE :search')
                ->setParameter('search', '%' . strtolower(trim($search)) . '%');
            $qb->groupBy('t.id');
        }

        $qb->orderBy('t.position', 'ASC')->addOrderBy('t.createdOn', 'DESC');
        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: true);
        $total = count($paginator);
        /** @var list<Task> $tasks */
        $tasks = array_values(iterator_to_array($paginator));
        $this->warmer->warm($tasks);
        $items = array_map(fn (Task $task) => $this->serializer->task($task), $tasks);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Tasks visible to the user: owned directly OR attached to a
     * board whose space the user belongs to (#185). Mirrors
     * {@see TaskOwnerExtension}.
     */
    private function buildBaseQuery(User $user): QueryBuilder
    {
        return $this->em->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->leftJoin('t.board', 'p')
            ->where('t.owner = :user OR ' . \App\Doctrine\SpaceMembershipDql::userBelongsToBoardSpace('p', 'list_tasks'))
            ->setParameter('user', $user);
    }
}
