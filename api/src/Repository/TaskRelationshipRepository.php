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
