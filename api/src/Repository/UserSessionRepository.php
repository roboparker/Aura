<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSession>
 */
class UserSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSession::class);
    }

    public function findOneByHash(string $hash): ?UserSession
    {
        return $this->findOneBy(['sessionIdHash' => $hash]);
    }

    /**
     * Active (non-revoked) sessions for a user, most-recently-seen first.
     *
     * @return list<UserSession>
     */
    public function findActiveForUser(User $user): array
    {
        /** @var list<UserSession> $rows */
        $rows = $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('s.lastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
        return $rows;
    }

    /**
     * Revoke every active session for the user except the one whose hash is
     * `$exceptHash`. Returns the number of sessions revoked.
     */
    /** Revoke every active session for the user. Returns the count revoked. */
    public function revokeAllForUser(User $user): int
    {
        return $this->createQueryBuilder('s')
            ->update()
            ->set('s.revokedAt', ':now')
            ->where('s.user = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function revokeOthers(User $user, string $exceptHash): int
    {
        return $this->createQueryBuilder('s')
            ->update()
            ->set('s.revokedAt', ':now')
            ->where('s.user = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.sessionIdHash != :hash')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->setParameter('hash', $exceptHash)
            ->getQuery()
            ->execute();
    }
}
