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
     *
     * @return list<Organization>
     */
    public function forMember(User $user): array
    {
        /** @var list<Organization> $orgs */
        $orgs = $this->createQueryBuilder('o')
            ->innerJoin('o.memberships', 'm')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $orgs;
    }
}
