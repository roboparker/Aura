<?php

declare(strict_types=1);

namespace App\Automation;

use App\Entity\Task;
use App\Entity\User;
use App\Message\RunAutomations;
use App\Repository\AutomationRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The one thing request-path code calls to say "something happened to a task"
 * (#764).
 *
 * Keeps the processors to a single line and keeps every decision about *when*
 * automations run in one place.
 *
 * **Enqueues, never executes.** Rules can do real work — several queries, a
 * comment, a notification — and none of that belongs on the latency path of
 * someone ticking a checkbox. It also means a broken rule can't fail the edit
 * that triggered it.
 */
final class AutomationDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private AutomationRepository $automations,
    ) {
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function dispatch(
        Task $task,
        string $event,
        array $before = [],
        array $after = [],
        ?User $actor = null,
        int $depth = 0,
    ): void {
        $board = $task->getBoard();
        $taskId = $task->getId();
        // A standalone task belongs to no board, so no board rules apply.
        if (null === $board || null === $taskId) {
            return;
        }

        // Cheap indexed check before paying for a queue round trip: most task
        // edits happen on boards with no rules at all, and enqueuing a job that
        // will find nothing is pure cost.
        if ([] === $this->automations->enabledFor($board, $event)) {
            return;
        }

        $this->bus->dispatch(new RunAutomations(
            taskId: (string) $taskId,
            event: $event,
            before: $before,
            after: $after,
            actorId: null === $actor?->getId() ? null : (string) $actor->getId(),
            depth: $depth,
        ));
    }
}
