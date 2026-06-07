<?php

namespace App\Repository;

use App\Entity\Segment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Segment>
 */
final class SegmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Segment::class);
    }

    public function findOneByKey(string $key): ?Segment
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * @return list<Segment>
     */
    public function findAllEnabled(): array
    {
        return array_values($this->findBy(['enabled' => true]));
    }
}
