<?php

namespace App\Repository;

use App\Entity\CalendarFeedToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarFeedToken>
 */
class CalendarFeedTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarFeedToken::class);
    }

    public function findByTokenHash(string $tokenHash): ?CalendarFeedToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findForUser(User $user): ?CalendarFeedToken
    {
        return $this->findOneBy(['user' => $user]);
    }
}
