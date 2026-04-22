<?php

namespace App\Repository;

use App\Entity\Todo;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Todo>
 */
final class TodoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Todo::class);
    }

    /**
     * Smallest position currently in use by the given owner, or null if they
     * have no todos. Callers subtract one to insert a new todo at the top.
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
