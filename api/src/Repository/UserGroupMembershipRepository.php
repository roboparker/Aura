<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserGroupMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserGroupMembership>
 */
class UserGroupMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserGroupMembership::class);
    }

    public function findByGroupAndUser(UserGroup $group, User $user): ?UserGroupMembership
    {
        return $this->findOneBy(['group' => $group, 'user' => $user]);
    }
}
