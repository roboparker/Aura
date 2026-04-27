<?php

namespace App\Repository;

use App\Entity\UserInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserInvite>
 */
final class UserInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserInvite::class);
    }

    public function findByTokenHash(string $tokenHash): ?UserInvite
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Email lookups are case-insensitive so re-inviting "Bob@Example.com"
     * after "bob@example.com" finds the same row.
     */
    public function findByEmail(string $email): ?UserInvite
    {
        return $this->createQueryBuilder('i')
            ->andWhere('LOWER(i.email) = LOWER(:email)')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
