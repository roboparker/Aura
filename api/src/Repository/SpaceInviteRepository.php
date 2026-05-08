<?php

namespace App\Repository;

use App\Entity\Space;
use App\Entity\SpaceInvite;
use App\Entity\UserInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpaceInvite>
 */
final class SpaceInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceInvite::class);
    }

    public function findByInviteAndSpace(UserInvite $invite, Space $space): ?SpaceInvite
    {
        return $this->findOneBy(['userInvite' => $invite, 'space' => $space]);
    }

    /**
     * Active invites attached to a space — those whose parent
     * `UserInvite` is still pending (not accepted, not expired).
     *
     * @return SpaceInvite[]
     */
    public function findActiveBySpace(Space $space): array
    {
        return $this->createQueryBuilder('si')
            ->innerJoin('si.userInvite', 'ui')
            ->where('si.space = :space')
            ->andWhere('ui.acceptedAt IS NULL')
            ->andWhere('ui.expiresAt > :now')
            ->setParameter('space', $space)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('si.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
