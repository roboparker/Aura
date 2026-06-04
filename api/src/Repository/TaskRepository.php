<?php

namespace App\Repository;

use App\Entity\SpaceGroupMembership;
use App\Entity\SpaceMembership;
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

    /**
     * Tasks the user is allowed to reorder: anything they own directly,
     * plus tasks attached to a project whose space they belong to
     * (#185). Mirrors the visibility rule in TaskOwnerExtension.
     *
     * @return Task[]
     */
    public function findReorderableForUser(User $user): array
    {
        $directSubquery = sprintf(
            'SELECT 1 FROM %s reorder_direct WHERE reorder_direct.space = p.space AND reorder_direct.user = :user',
            SpaceMembership::class,
        );
        $groupSubquery = sprintf(
            'SELECT 1 FROM %s reorder_group JOIN reorder_group.userGroup reorder_group_obj JOIN reorder_group_obj.memberships reorder_group_member WHERE reorder_group.space = p.space AND reorder_group_member.user = :user',
            SpaceGroupMembership::class,
        );
        return $this->createQueryBuilder('t')
            ->leftJoin('t.project', 'p')
            ->where(sprintf(
                't.owner = :user OR EXISTS(%s) OR EXISTS(%s)',
                $directSubquery,
                $groupSubquery,
            ))
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
