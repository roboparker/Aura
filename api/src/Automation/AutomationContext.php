<?php

declare(strict_types=1);

namespace App\Automation;

use App\Entity\Task;
use App\Entity\User;

/**
 * Everything one execution knows: the task that changed, what changed about
 * it, who caused it, and how deep into an automation chain we already are.
 *
 * The **change snapshot** is carried rather than re-derived. Execution is
 * async, so by the time the worker runs, the task may have moved on again —
 * evaluating "did the assignee just change" against current state would be
 * answering a different question than the one that fired the rule.
 */
final class AutomationContext
{
    /**
     * How many automation-caused changes deep we are.
     *
     * The defence against rule A editing a task, which fires rule B, which
     * edits it back. No single-graph cycle check can see that — only a counter
     * that survives across executions can.
     */
    public const MAX_DEPTH = 3;

    /**
     * @param array<string, mixed> $before field => value before the change
     * @param array<string, mixed> $after  field => value after the change
     */
    public function __construct(
        public readonly Task $task,
        public readonly string $event,
        public readonly array $before = [],
        public readonly array $after = [],
        public readonly ?User $actor = null,
        public readonly int $depth = 0,
    ) {
    }

    /** Whether this run is allowed to make further changes. */
    public function withinDepthLimit(): bool
    {
        return $this->depth < self::MAX_DEPTH;
    }

    /** Whether a named field was part of this change. */
    public function changed(string $field): bool
    {
        if (!array_key_exists($field, $this->after)) {
            return false;
        }

        return ($this->before[$field] ?? null) !== $this->after[$field];
    }

    public function valueBefore(string $field): mixed
    {
        return $this->before[$field] ?? null;
    }

    public function valueAfter(string $field): mixed
    {
        return $this->after[$field] ?? null;
    }
}
