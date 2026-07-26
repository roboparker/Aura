<?php

declare(strict_types=1);

namespace App\Automation\Trigger;

use App\Automation\AutomationContext;
use App\Automation\AutomationEvents;
use App\Automation\TriggerDefinitionInterface;

/**
 * Fires when a task is marked complete.
 *
 * Listens for the dedicated completion event rather than for a generic update,
 * so it can't fire again on every subsequent edit of an already-complete task.
 */
final class TaskCompletedTrigger implements TriggerDefinitionInterface
{
    public function type(): string
    {
        return 'task.completed';
    }

    public function label(): string
    {
        return 'Task is completed';
    }

    public function description(): string
    {
        return 'Runs the moment a task is ticked off.';
    }

    public function event(): string
    {
        return AutomationEvents::TASK_COMPLETED;
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
        return true;
    }
}
