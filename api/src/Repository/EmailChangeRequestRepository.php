<?php

namespace App\Repository;

use App\Entity\EmailChangeRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailChangeRequest>
 */
class EmailChangeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailChangeRequest::class);
    }

    public function findByConfirmTokenHash(string $tokenHash): ?EmailChangeRequest
    {
        return $this->findOneBy(['confirmTokenHash' => $tokenHash]);
    }

    public function findByRevertTokenHash(string $tokenHash): ?EmailChangeRequest
    {
        return $this->findOneBy(['revertTokenHash' => $tokenHash]);
    }

    /**
     * Cancels every pending (not yet confirmed, not yet cancelled)
     * change request for a user. Called whenever we issue a fresh
     * request so a user only ever has one outstanding confirm token.
     */
    public function cancelPendingForUser(User $user): void
    {
        $this->createQueryBuilder('r')
            ->update()
            ->set('r.cancelledAt', ':now')
            ->where('r.user = :user')
            ->andWhere('r.confirmedAt IS NULL')
            ->andWhere('r.cancelledAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
