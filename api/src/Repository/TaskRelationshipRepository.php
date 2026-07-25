<?php

namespace App\Repository;

use App\Entity\Task;
use App\Entity\TaskRelationship;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskRelationship>
 */
class TaskRelationshipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskRelationship::class);
    }

    /**
     * Every relationship touching the given task, on either side.
     *
     * @return TaskRelationship[]
     */
    public function findForTask(Task $task): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.source = :task OR r.target = :task')
            ->setParameter('task', $task)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The `parent` relationship whose *target* is the given task, if any — i.e.
     * the task's parent link. A subtask has at most one parent, so this backs
     * the single-parent invariant ({@see ValidTaskRelationshipValidator}) and
     * the subtasks endpoint.
     */
    public function findParentLinkOf(Task $child): ?TaskRelationship
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.type = :parent')
            ->andWhere('r.target = :child')
            ->setParameter('parent', TaskRelationship::TYPE_PARENT)
            ->setParameter('child', $child)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof TaskRelationship ? $result : null;
    }

    /**
     * The `parent` relationships whose *source* is the given task — its
     * subtasks, oldest first. Joins the child so a caller can read completion
     * without a follow-up query.
     *
     * @return TaskRelationship[]
     */
    public function findChildLinksOf(Task $parent): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('child')
            ->join('r.target', 'child')
            ->where('r.type = :parent')
            ->andWhere('r.source = :task')
            ->setParameter('parent', TaskRelationship::TYPE_PARENT)
            ->setParameter('task', $parent)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Edges of the given types where **both** tasks sit in the given space —
     * powering the cross-board `/timeline` view. Unlike the board-scoped
     * sibling this keeps links that span two boards, because the space chart
     * has a row for both ends. Both ends must still be inside the space, so a
     * link reaching into a space the caller can't see never appears.
     *
     * @param list<string> $types
     *
     * @return list<array{source: string, target: string, type: string}>
     */
    public function findEdgesInSpace(\App\Entity\Space $space, array $types): array
    {
        if ([] === $types) {
            return [];
        }

        /** @var list<array{source: string, target: string, type: string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.source) AS source', 'IDENTITY(r.target) AS target', 'r.type AS type')
            ->join('r.source', 's')
            ->join('r.target', 't')
            ->join('s.board', 'sb')
            ->join('t.board', 'tb')
            ->where('r.type IN (:types)')
            ->andWhere('sb.space = :space')
            ->andWhere('tb.space = :space')
            ->setParameter('types', $types)
            ->setParameter('space', $space)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Edges of the given types where **both** tasks belong to the board — what
     * a board's Timeline tab draws. `required` becomes a dependency arrow;
     * `parent` lets the chart nest subtask rows under their parent. Edges
     * crossing out of the board are omitted, since the other end has no row.
     *
     * Direction is source→target throughout: for `required` the source is the
     * predecessor; for `parent` the source is the parent task.
     *
     * @param list<string> $types
     *
     * @return list<array{source: string, target: string, type: string}>
     */
    public function findEdgesInBoard(\App\Entity\Board $board, array $types): array
    {
        if ([] === $types) {
            return [];
        }

        /** @var list<array{source: string, target: string, type: string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.source) AS source', 'IDENTITY(r.target) AS target', 'r.type AS type')
            ->join('r.source', 's')
            ->join('r.target', 't')
            ->where('r.type IN (:types)')
            ->andWhere('s.board = :board')
            ->andWhere('t.board = :board')
            ->setParameter('types', $types)
            ->setParameter('board', $board)
            ->getQuery()
            ->getResult();

        // IDENTITY() already yields the id string; the aliased shape is exactly
        // the return contract, so no re-mapping is needed.
        return $rows;
    }

    /**
     * Subtask counts for many parents in **one** query — the rollup behind the
     * "2/5" badge on task rows and the MCP task payload.
     *
     * Batched deliberately: the per-parent alternative
     * ({@see findChildLinksOf}) is fine for one task in the drawer, but a board
     * page or an MCP `list_tasks` page would fan it out into an N+1. Parents
     * with no children are simply absent from the result — callers treat a
     * missing key as `{total: 0, completed: 0}`.
     *
     * @param list<string> $parentIds
     *
     * @return array<string, array{total: int, completed: int}>
     */
    public function subtaskProgressFor(array $parentIds): array
    {
        if ([] === $parentIds) {
            return [];
        }

        /** @var list<array{parentId: string, total: int|string, completed: int|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select(
                'IDENTITY(r.source) AS parentId',
                'COUNT(child.id) AS total',
                'SUM(CASE WHEN child.completedOn IS NULL THEN 0 ELSE 1 END) AS completed',
            )
            ->join('r.target', 'child')
            ->where('r.type = :parent')
            ->andWhere('IDENTITY(r.source) IN (:ids)')
            ->setParameter('parent', TaskRelationship::TYPE_PARENT)
            ->setParameter('ids', $parentIds)
            ->groupBy('parentId')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['parentId']] = [
                'total' => (int) $row['total'],
                'completed' => (int) $row['completed'],
            ];
        }

        return $out;
    }

    /**
     * The relationship between two tasks of a given type, in either direction
     * (used to reject duplicates/reverses before insert).
     */
    public function findBetween(Task $a, Task $b, string $type): ?TaskRelationship
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.type = :type')
            ->andWhere(
                '(r.source = :a AND r.target = :b) OR (r.source = :b AND r.target = :a)',
            )
            ->setParameter('type', $type)
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof TaskRelationship ? $result : null;
    }
}
