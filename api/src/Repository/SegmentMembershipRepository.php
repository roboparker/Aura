<?php

namespace App\Repository;

use App\Entity\Segment;
use App\Entity\SegmentMembership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SegmentMembership>
 */
final class SegmentMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SegmentMembership::class);
    }

    public function findOneForUser(Segment $segment, User $user): ?SegmentMembership
    {
        return $this->findOneBy(['segment' => $segment, 'user' => $user]);
    }

    /**
     * All of a user's manual override rows in one query, so the evaluator can
     * build a segmentId → mode map without N round-trips.
     *
     * @return list<SegmentMembership>
     */
    public function findForUser(User $user): array
    {
        return array_values($this->findBy(['user' => $user]));
    }
}
