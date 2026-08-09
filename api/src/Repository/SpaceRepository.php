<?php

namespace App\Repository;

use App\Entity\Space;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Space>
 */
final class SpaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Space::class);
    }

    /**
     * Soft-deleted spaces whose grace period has lapsed, oldest first so a
     * backlog drains in the order the deletions were requested.
     *
     * @return list<Space>
     */
    public function findDueForPurge(\DateTimeImmutable $now, int $limit = 50): array
    {
        /** @var list<Space> $spaces */
        $spaces = $this->createQueryBuilder('s')
            ->where('s.deletedAt IS NOT NULL')
            ->andWhere('s.purgeAfter IS NOT NULL')
            ->andWhere('s.purgeAfter <= :now')
            ->setParameter('now', $now)
            ->orderBy('s.purgeAfter', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $spaces;
    }

    /**
     * Locate the user's personal space — the one auto-created at
     * signup. The DB-level partial unique index `uniq_space_personal_per_user`
     * guarantees at most one row matches; we still take the first hit
     * defensively in case the index is dropped during a future migration.
     */
    public function findPersonalSpaceFor(User $user): ?Space
    {
        return $this->findOneBy([
            'createdBy' => $user,
            'isPersonal' => true,
        ]);
    }
}
