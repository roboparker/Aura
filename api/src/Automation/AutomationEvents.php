<?php

declare(strict_types=1);

namespace App\Automation;

/**
 * The lifecycle events rules can hang off (#764).
 *
 * Deliberately a small, closed list. Every one of these has to be raised from
 * somewhere real in the request path, so the set grows only when a hook is
 * actually wired — not speculatively.
 */
final class AutomationEvents
{
    /** A task was created on a board. */
    public const TASK_CREATED = 'task.created';

    /** Any field on a task changed. Triggers narrow it further. */
    public const TASK_UPDATED = 'task.updated';

    /** A task flipped from incomplete to complete. */
    public const TASK_COMPLETED = 'task.completed';

    /**
     * Raised by the scheduler rather than by a request — nothing "happens" when
     * a due date passes, so something has to go looking.
     */
    public const TASK_DUE = 'task.due';

    /**
     * A task was deleted from a board.
     *
     * The odd one out: by the time rules run, the row is gone. Conditions read
     * a snapshot taken before deletion, and only actions marked
     * {@see SurvivesTaskDeletion} can run — everything else has nothing left to
     * edit. See {@see AutomationRunner} for how that's enforced.
     */
    public const TASK_DELETED = 'task.deleted';

    /** @var list<string> */
    public const ALL = [
        self::TASK_CREATED,
        self::TASK_UPDATED,
        self::TASK_COMPLETED,
        self::TASK_DUE,
        self::TASK_DELETED,
    ];

    public static function isValid(string $event): bool
    {
        return in_array($event, self::ALL, true);
    }
}
