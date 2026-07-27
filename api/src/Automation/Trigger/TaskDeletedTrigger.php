<?php

declare(strict_types=1);

namespace App\Automation\Trigger;

use App\Automation\AutomationContext;
use App\Automation\AutomationEvents;
use App\Automation\TriggerDefinitionInterface;

/**
 * Fires when a task is deleted from the board.
 *
 * Useful for noticing work disappearing — "tell the lead when anything is
 * deleted from the release board". Only notifying actions can follow it, since
 * the task itself no longer exists to be changed.
 */
final class TaskDeletedTrigger implements TriggerDefinitionInterface
{
    public function type(): string
    {
        return 'task.deleted';
    }

    public function label(): string
    {
        return 'A task is deleted';
    }

    public function description(): string
    {
        return 'Runs when a task is deleted from this board. Only notification actions can follow — the task is gone by then.';
    }

    public function event(): string
    {
        return AutomationEvents::TASK_DELETED;
    }

    public function configSchema(): array
    {
        return [];
    }

    public function validateConfig(array $config): ?string
    {
        return null;
    }

    public function matches(AutomationContext $context, array $config): bool
    {
        // The event itself is the whole condition; nothing further to narrow.
        return AutomationEvents::TASK_DELETED === $context->event;
    }
}
