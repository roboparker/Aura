<?php

namespace App\Mcp\Tool;

use App\Entity\Comment;
use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class SearchTasksTool implements McpToolInterface
{
    private const MAX_LIMIT = 50;

    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'search_tasks';
    }

    public function getDescription(): string
    {
        return 'Full-text search over tasks the user can read, matching against title, description, and comment bodies. Returns up to "limit" results (default 20, max 50). Optionally narrow to one projectId.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search string (case-insensitive substring).'],
                'projectId' => ['type' => 'string', 'description' => 'Restrict to one project.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => 20],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $query = trim($this->input->requireString($arguments, 'query'));
        if ('' === $query) {
            throw McpException::invalidInput('query must contain at least one non-whitespace character.');
        }
        $limit = $this->input->optionalInt($arguments, 'limit') ?? 20;
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $qb = $this->em->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->leftJoin('t.project', 'p')
            ->leftJoin('p.members', 'pm', 'WITH', 'pm = :user')
            ->leftJoin(Comment::class, 'c', 'WITH', 'c.task = t')
            ->where('t.owner = :user OR pm IS NOT NULL')
            ->andWhere('LOWER(t.title) LIKE :q OR LOWER(t.description) LIKE :q OR LOWER(c.body) LIKE :q')
            ->setParameter('user', $user)
            ->setParameter('q', '%' . strtolower($query) . '%')
            ->groupBy('t.id')
            ->orderBy('t.createdOn', 'DESC')
            ->setMaxResults($limit);

        if (null !== $projectId = $this->input->optionalUuid('projectId', $arguments['projectId'] ?? null)) {
            $qb->andWhere('t.project = :projectId')->setParameter('projectId', $projectId);
        }

        $tasks = $qb->getQuery()->getResult();
        return [
            'items' => array_map(fn (Task $task) => $this->serializer->task($task), $tasks),
            'query' => $query,
        ];
    }
}
