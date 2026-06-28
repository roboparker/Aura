<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\ProjectFieldVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectFieldVisibility>
 */
class ProjectFieldVisibilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectFieldVisibility::class);
    }

    /**
     * Visibility overrides for a project, keyed by definition UUID string.
     *
     * @return array<string, string> definitionId => visibility
     */
    public function visibilityMapForProject(Project $project): array
    {
        $map = [];
        foreach ($this->findBy(['project' => $project]) as $row) {
            $defId = $row->getDefinition()?->getId();
            if (null !== $defId) {
                $map[(string) $defId] = $row->getVisibility();
            }
        }

        return $map;
    }

    public function findOneFor(
        Project $project,
        CustomFieldDefinition $definition,
    ): ?ProjectFieldVisibility {
        return $this->findOneBy(['project' => $project, 'definition' => $definition]);
    }
}
