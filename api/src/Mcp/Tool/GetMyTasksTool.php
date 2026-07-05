<?php

namespace App\Mcp\Tool;

use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Mcp\TaskRelationWarmer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class GetMyTasksTool implements McpToolInterface
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
        return 'get_my_tasks';
    }

    public function getDescription(): string
    {
        return 'List tasks assigned to the authenticated user. Optionally narrow to one board, status (open/completed), or due-before cutoff. Paginated.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['open', 'completed']],
                'boardId' => ['type' => 'string'],
                'dueBefore' => ['type' => 'string', 'description' => 'ISO-8601 datetime cutoff.'],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        ['page' => $page, 'limit' => $limit] = $this->input->pagination($arguments);

        $qb = $this->em->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->innerJoin('t.assignees', 'a', 'WITH', 'a = :user')
            ->setParameter('user', $user);

        if (null !== $status = $this->input->optionalString($arguments, 'status')) {
            if ('open' === $status) {
                $qb->andWhere('t.completedOn IS NULL');
            } elseif ('completed' === $status) {
                $qb->andWhere('t.completedOn IS NOT NULL');
            } else {
                throw McpException::invalidInput('status must be "open" or "completed".');
            }
        }
        if (null !== $boardId = $this->input->optionalUuid('boardId', $arguments['boardId'] ?? null)) {
            $qb->andWhere('t.board = :boardId')->setParameter('boardId', $boardId);
        }
        if (null !== $dueBefore = $this->input->optionalDateTime($arguments, 'dueBefore')) {
            $qb->andWhere('t.dueDate < :dueBefore')->setParameter('dueBefore', $dueBefore);
        }

        $qb->orderBy('t.dueDate', 'ASC')->addOrderBy('t.createdOn', 'DESC');
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
}
