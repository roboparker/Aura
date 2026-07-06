<?php

namespace App\Mcp;

use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Batch-initializes the relations {@see McpEntitySerializer::task()} reads
 * so a page of tasks serializes without an N+1 storm. Each task's
 * `assignees`/`tags`/`attachments` collections and `owner`/`board`
 * proxies would otherwise lazy-load one query at a time (~5 queries per
 * task); we warm them with a fixed five `WHERE id IN (...)` fetch-joins for
 * the whole page instead.
 *
 * Each relation is warmed in its own query rather than one combined join so
 * the to-many collections never form a cartesian product, and so a `GROUP
 * BY t.id` search query upstream is left untouched.
 */
final class TaskRelationWarmer
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<Task> $tasks
     */
    public function warm(array $tasks): void
    {
        if ([] === $tasks) {
            return;
        }

        $ids = array_map(static fn (Task $task) => $task->getId(), $tasks);

        foreach (['assignees', 'tags', 'attachments', 'owner', 'board'] as $relation) {
            $this->em->createQueryBuilder()
                ->select('t', 'r')
                ->from(Task::class, 't')
                ->leftJoin('t.' . $relation, 'r')
                ->where('t.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->getResult();
        }
    }
}
