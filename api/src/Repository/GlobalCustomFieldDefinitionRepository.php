<?php

namespace App\Repository;

use App\Entity\GlobalCustomFieldDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GlobalCustomFieldDefinition>
 */
class GlobalCustomFieldDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GlobalCustomFieldDefinition::class);
    }

    /**
     * The canonical "Start date" field the board Timeline (#timeline) reads,
     * resolved by its stable systemKey rather than name. Seeded by migration,
     * so this is non-null in practice; callers still guard for null in case a
     * fresh install hasn't run migrations.
     */
    public function findTimelineStartField(): ?GlobalCustomFieldDefinition
    {
        return $this->findOneBy(['systemKey' => GlobalCustomFieldDefinition::SYSTEM_TIMELINE_START]);
    }
}
