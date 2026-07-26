<?php

declare(strict_types=1);

namespace App\Automation;

use App\Entity\Task;

/**
 * Flattens the parts of a task that triggers ask about, as plain scalars
 * (#764).
 *
 * Two jobs, and both need scalars rather than entities:
 *
 * 1. The change snapshot rides a Messenger message to a worker, so it has to
 *    survive serialisation.
 * 2. Comparing before and after has to work on values. Entity identity doesn't
 *    help — `previous_data` shares collection references with the live task, so
 *    an assignee list compared by object looks unchanged even when it isn't.
 */
final class TaskSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Task $task): array
    {
        $assignees = [];
        foreach ($task->getAssignees() as $assignee) {
            $assignees[] = (string) $assignee->getId();
        }
        sort($assignees);

        $tags = [];
        foreach ($task->getTags() as $tag) {
            $tags[] = mb_strtolower($tag->getTitle());
        }
        sort($tags);

        return [
            'section' => null === $task->getSection() ? null : (string) $task->getSection()->getId(),
            'dueDate' => $task->getDueDate()?->format('Y-m-d'),
            'completed' => null !== $task->getCompletedOn(),
            'title' => $task->getTitle(),
            // Sorted, so reordering isn't mistaken for a change.
            'assignees' => $assignees,
            'tags' => $tags,
        ];
    }

    /**
     * Whether anything a trigger could notice actually differs.
     *
     * Used to decide if a rule's edits are worth re-running rules over — a rule
     * that changed nothing shouldn't start a chain.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function differs(array $before, array $after): bool
    {
        return $before !== $after;
    }
}
