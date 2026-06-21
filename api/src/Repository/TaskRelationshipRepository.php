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
     * The relationship between two tasks of a given type, in either direction
     * (used to reject duplicates/reverses before insert).
     */
    public function findBetween(Task $a, Task $b, string $type): ?TaskRelationship
    {
        return $this->createQueryBuilder('r')
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
    }
}
