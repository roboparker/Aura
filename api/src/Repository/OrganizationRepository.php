<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
final class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /**
     * Organizations the user is a member of (any role), newest first.
     * Excludes organizations in their post-deletion grace period.
     *
     * @return list<Organization>
     */
    public function forMember(User $user): array
    {
        /** @var list<Organization> $orgs */
        $orgs = $this->createQueryBuilder('o')
            ->innerJoin('o.memberships', 'm')
            ->where('m.user = :user')
            ->andWhere('o.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $orgs;
    }

    /**
     * Soft-deleted organizations whose grace period has lapsed, so the purge
     * may hard-delete them. Ordered oldest-first so a backlog drains in the
     * order the deletions were requested.
     *
     * @return list<Organization>
     */
    public function findDueForPurge(\DateTimeImmutable $now, int $limit = 50): array
    {
        /** @var list<Organization> $orgs */
        $orgs = $this->createQueryBuilder('o')
            ->where('o.deletedAt IS NOT NULL')
            ->andWhere('o.purgeAfter IS NOT NULL')
            ->andWhere('o.purgeAfter <= :now')
            ->setParameter('now', $now)
            ->orderBy('o.purgeAfter', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $orgs;
    }

    /**
     * Organizations the user could still restore: soft-deleted, within the
     * window, and they're an owner. Powers the "scheduled for deletion" surface
     * — without it a deleted org vanishes from every listing and the owner has
     * no way back to it.
     *
     * @return list<Organization>
     */
    public function restorableFor(User $user): array
    {
        /** @var list<Organization> $orgs */
        $orgs = $this->createQueryBuilder('o')
            ->innerJoin('o.memberships', 'm')
            ->where('m.user = :user')
            ->andWhere('m.role = :owner')
            ->andWhere('o.deletedAt IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('owner', Organization::ROLE_OWNER)
            ->orderBy('o.deletedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $orgs;
    }
}
