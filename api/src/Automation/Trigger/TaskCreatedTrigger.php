<?php

declare(strict_types=1);

namespace App\Automation\Trigger;

use App\Automation\AutomationContext;
use App\Automation\AutomationEvents;
use App\Automation\TriggerDefinitionInterface;

/**
 * Fires when a task is added to the board.
 *
 * The simplest possible trigger, and the reference implementation: no config,
 * so {@see matches()} is unconditionally true once the event lines up.
 */
final class TaskCreatedTrigger implements TriggerDefinitionInterface
{
    public function type(): string
    {
        return 'task.created';
    }

    public function label(): string
    {
        return 'Task is created';
    }

    public function description(): string
    {
        return 'Runs when a new task is added to this board.';
    }

    public function event(): string
    {
        return AutomationEvents::TASK_CREATED;
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
