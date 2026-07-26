<?php

namespace App\Message;

/**
 * Run a board's rules against one task change (#764).
 *
 * Carries **ids and scalars, never entities** — it crosses into a worker
 * process, where a serialised entity would be stale or unloadable.
 *
 * The before/after snapshot travels with the message rather than being
 * re-derived on the other side: by the time the worker runs, the task may have
 * changed again, and asking "did the section just change" of current state
 * would answer a different question than the one that fired the rule.
 */
final class RunAutomations
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function __construct(
        public readonly string $taskId,
        public readonly string $event,
        public readonly array $before = [],
        public readonly array $after = [],
        public readonly ?string $actorId = null,
        /** How many automation-caused changes deep this already is. */
        public readonly int $depth = 0,
    ) {
    }
}
