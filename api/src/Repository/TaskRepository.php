<?php

namespace App\Repository;

use App\Entity\Task;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
final class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Smallest position currently in use by the given owner, or null if they
     * have no tasks. Callers subtract one to insert a new task at the top.
     */
    public function findMinPositionForOwner(User $owner): ?int
    {
        $min = $this->createQueryBuilder('t')
            ->select('MIN(t.position)')
            ->where('t.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $min ? null : (int) $min;
    }
}
