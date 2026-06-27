<?php

namespace App\Service;

use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves the audit-log object-id groups that make up a project's activity —
 * the project row itself plus every task and custom-field definition that
 * belongs to it, INCLUDING ones that have since been deleted (their log rows
 * outlive the entity). Recovery keys on the versioned `project` association
 * Gedmo records on each entry as `{"id": "<uuid>"}`.
 *
 * Shared so the per-level activity feeds form a consistent hierarchy — the
 * project feed ({@see \App\Controller\ProjectActivityController}) uses it
 * directly, and the space feed ({@see \App\Controller\SpaceActivityController})
 * rolls it up across every project in the space. Both then hand the groups to
 * {@see ActivityFeedQuery::forObjectGroups()}, so the wire shape stays
 * identical at every level.
 */
final class ProjectActivityScope
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Object-id groups for a single project's activity (project + tasks + CFDs,
     * including deleted children).
     *
     * @return array<class-string, list<string>>
     */
    public function groupsForProject(Project $project): array
    {
        $projectId = (string) $project->getId();

        $taskIds = array_map(
            static fn (Task $t): string => (string) $t->getId(),
            $project->getTasks()->toArray(),
        );

        // Fields are space-owned but per-project shown; surface the fields this
        // project has opted into. Per-project deleted-field recovery no longer
        // applies (field history lives at the space level).
        $cfdIds = array_map(
            static fn (CustomFieldDefinition $d): string => (string) $d->getId(),
            $project->getCustomFieldDefinitions()->toArray(),
        );

        return [
            Project::class => [$projectId],
            Task::class => array_values(array_unique(
                [...$taskIds, ...$this->deletedChildIds(Task::class, $projectId)],
            )),
            CustomFieldDefinition::class => array_values(array_unique(
                [...$cfdIds, ...$this->deletedChildIds(CustomFieldDefinition::class, $projectId)],
            )),
        ];
    }

    /**
     * Object ids of `$class` rows whose audit entries record `$projectId` via
     * the versioned `project` association — covers deleted children the entity
     * no longer references.
     *
     * @return list<string>
     */
    public function deletedChildIds(string $class, string $projectId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT object_id
            FROM ext_log_entries
            WHERE object_class = :class AND data->'project'->>'id' = :pid
            SQL;
        $rows = $this->em->getConnection()
            ->executeQuery($sql, ['class' => $class, 'pid' => $projectId])
            ->fetchFirstColumn();

        // object_id is a VARCHAR column, so values are strings; filter defensively.
        return array_values(array_filter(
            $rows,
            static fn (mixed $oid): bool => is_string($oid),
        ));
    }
}
