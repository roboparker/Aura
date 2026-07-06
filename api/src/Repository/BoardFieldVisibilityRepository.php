<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomFieldDefinition;
use App\Entity\GlobalCustomFieldDefinition;
use App\Entity\Board;
use App\Entity\BoardFieldVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoardFieldVisibility>
 */
class BoardFieldVisibilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BoardFieldVisibility::class);
    }

    /**
     * Visibility overrides for a board, keyed by definition UUID string.
     * Covers both field sources — space and global override rows land in the
     * same map (their UUIDs never collide) so the collection providers can
     * look up either without caring which source they hold.
     *
     * @return array<string, string> definitionId => visibility
     */
    public function visibilityMapForProject(Board $board): array
    {
        $map = [];
        foreach ($this->findBy(['board' => $board]) as $row) {
            $defId = $row->getEffectiveDefinition()?->getId();
            if (null !== $defId) {
                $map[(string) $defId] = $row->getVisibility();
            }
        }

        return $map;
    }

    public function findOneFor(
        Board $board,
        CustomFieldDefinition $definition,
    ): ?BoardFieldVisibility {
        return $this->findOneBy(['board' => $board, 'definition' => $definition]);
    }

    public function findOneForGlobal(
        Board $board,
        GlobalCustomFieldDefinition $definition,
    ): ?BoardFieldVisibility {
        return $this->findOneBy(['board' => $board, 'globalDefinition' => $definition]);
    }
}
