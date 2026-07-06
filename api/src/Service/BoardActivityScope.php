<?php

namespace App\Service;

use App\Entity\CustomFieldDefinition;
use App\Entity\Board;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves the audit-log object-id groups that make up a board's activity —
 * the board row itself plus every task and custom-field definition that
 * belongs to it, INCLUDING ones that have since been deleted (their log rows
 * outlive the entity). Recovery keys on the versioned `board` association
 * Gedmo records on each entry as `{"id": "<uuid>"}`.
 *
 * Shared so the per-level activity feeds form a consistent hierarchy — the
 * board feed ({@see \App\Controller\BoardActivityController}) uses it
 * directly, and the space feed ({@see \App\Controller\SpaceActivityController})
 * rolls it up across every board in the space. Both then hand the groups to
 * {@see ActivityFeedQuery::forObjectGroups()}, so the wire shape stays
 * identical at every level.
 */
final class BoardActivityScope
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Object-id groups for a single board's activity (board + tasks + CFDs,
     * including deleted children).
     *
     * @return array<class-string, list<string>>
     */
    public function groupsForProject(Board $board): array
    {
        $boardId = (string) $board->getId();

        $taskIds = array_map(
            static fn (Task $t): string => (string) $t->getId(),
            $board->getTasks()->toArray(),
        );

        // Fields are space-owned but per-board shown; surface the fields this
        // board has opted into. Per-board deleted-field recovery no longer
        // applies (field history lives at the space level).
        $cfdIds = array_map(
            static fn (CustomFieldDefinition $d): string => (string) $d->getId(),
            $board->getCustomFieldDefinitions()->toArray(),
        );

        return [
            Board::class => [$boardId],
            Task::class => array_values(array_unique(
                [...$taskIds, ...$this->deletedChildIds(Task::class, $boardId)],
            )),
            CustomFieldDefinition::class => array_values(array_unique(
                [...$cfdIds, ...$this->deletedChildIds(CustomFieldDefinition::class, $boardId)],
            )),
        ];
    }

    /**
     * Object ids of `$class` rows whose audit entries record `$boardId` via
     * the versioned `board` association — covers deleted children the entity
     * no longer references.
     *
     * @return list<string>
     */
    public function deletedChildIds(string $class, string $boardId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT object_id
            FROM ext_log_entries
            WHERE object_class = :class AND data->'board'->>'id' = :pid
            SQL;
        $rows = $this->em->getConnection()
            ->executeQuery($sql, ['class' => $class, 'pid' => $boardId])
            ->fetchFirstColumn();

        // object_id is a VARCHAR column, so values are strings; filter defensively.
        return array_values(array_filter(
            $rows,
            static fn (mixed $oid): bool => is_string($oid),
        ));
    }
}
