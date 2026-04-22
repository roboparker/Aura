<?php

namespace App\Repository;

use App\Entity\PasswordResetToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetToken>
 */
class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function findByTokenHash(string $tokenHash): ?PasswordResetToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Invalidates all outstanding tokens for a user.
     */
    public function invalidateAllForUser(int $userId): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.usedAt', ':now')
            ->where('t.user = :userId')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }
}
