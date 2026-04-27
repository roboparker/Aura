<?php

namespace App\Repository;

use App\Entity\GroupInvite;
use App\Entity\UserGroup;
use App\Entity\UserInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupInvite>
 */
final class GroupInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupInvite::class);
    }

    public function findByInviteAndGroup(UserInvite $invite, UserGroup $group): ?GroupInvite
    {
        return $this->findOneBy(['userInvite' => $invite, 'group' => $group]);
    }

    /**
     * Active = the parent UserInvite hasn't been accepted and hasn't expired.
     * Used to render the owner-facing pending-invites list on a group page.
     *
     * @return GroupInvite[]
     */
    public function findActiveByGroup(UserGroup $group): array
    {
        return $this->createQueryBuilder('gi')
            ->innerJoin('gi.userInvite', 'ui')
            ->andWhere('gi.group = :group')
            ->andWhere('ui.acceptedAt IS NULL')
            ->andWhere('ui.expiresAt > :now')
            ->setParameter('group', $group)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('gi.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
