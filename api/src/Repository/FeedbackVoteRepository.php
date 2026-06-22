<?php

namespace App\Repository;

use App\Entity\Feedback;
use App\Entity\FeedbackVote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeedbackVote>
 */
final class FeedbackVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedbackVote::class);
    }

    public function findOneForUser(Feedback $feedback, User $user): ?FeedbackVote
    {
        return $this->findOneBy(['feedback' => $feedback, 'user' => $user]);
    }

    /**
     * Aggregate score (sum of +1 / -1 votes) for a single ticket.
     */
    public function scoreFor(Feedback $feedback): int
    {
        $sum = $this->createQueryBuilder('v')
            ->select('COALESCE(SUM(v.value), 0)')
            ->where('v.feedback = :feedback')
            ->setParameter('feedback', $feedback)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $sum;
    }
}
