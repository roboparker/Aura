<?php

declare(strict_types=1);

namespace App\Automation\Action;

use App\Automation\ActionDefinitionInterface;
use App\Automation\AutomationContext;
use App\Entity\User;

/**
 * Clears every assignee — the usual partner to "moved back to Backlog".
 *
 * Takes no config, so there is nothing to point at the wrong space.
 */
final class UnassignAllAction implements ActionDefinitionInterface
{
    public function type(): string
    {
        return 'task.unassign_all';
    }

    public function label(): string
    {
        return 'Remove all assignees';
    }

    public function description(): string
    {
        return 'Takes everyone off the task.';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function validateConfig(array $config): ?string
    {
        return null;
    }

    public function execute(AutomationContext $context, array $config): string
    {
        $task = $context->task;
        $removed = 0;
        // Snapshot first: removing while iterating the live collection skips
        // elements.
        foreach ($task->getAssignees()->toArray() as $assignee) {
            $task->removeAssignee($assignee);
            ++$removed;
        }

        return 0 === $removed ? 'nobody was assigned' : sprintf('removed %d assignee(s)', $removed);
    }
}
