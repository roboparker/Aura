<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RestoreToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<RestoreToken>
 */
final class RestoreTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RestoreToken::class);
    }

    public function findByTokenHash(string $tokenHash): ?RestoreToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Every token issued for a target, so restoring or purging it can retire
     * them all — a target deleted, restored, and deleted again has more than
     * one, and the older links must stop working the moment a newer decision
     * is made.
     *
     * @return list<RestoreToken>
     */
    public function findForTarget(string $targetType, Uuid $targetId): array
    {
        /** @var list<RestoreToken> $tokens */
        $tokens = $this->findBy(['targetType' => $targetType, 'targetId' => $targetId]);

        return $tokens;
    }

    /** Drop spent/expired rows; called from the nightly purge. */
    public function pruneExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
