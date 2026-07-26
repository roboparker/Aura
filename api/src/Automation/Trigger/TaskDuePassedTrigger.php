<?php

declare(strict_types=1);

namespace App\Automation\Trigger;

use App\Automation\AutomationContext;
use App\Automation\AutomationEvents;
use App\Automation\TriggerDefinitionInterface;

/**
 * Fires when a task's due date passes while it's still open.
 *
 * The only trigger not driven by a request: nothing *happens* when a date
 * passes, so the scheduler has to go looking (see `SweepDueTasks`). That's also
 * why it can only be as timely as the sweep interval, which the description
 * says out loud rather than leaving people to discover.
 */
final class TaskDuePassedTrigger implements TriggerDefinitionInterface
{
    public function type(): string
    {
        return 'task.due_passed';
    }

    public function label(): string
    {
        return 'Due date passes';
    }

    public function description(): string
    {
        return 'Runs once when an open task becomes overdue. Checked hourly, so it may lag a little.';
    }

    public function event(): string
    {
        return AutomationEvents::TASK_DUE;
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
        // The sweep already selected overdue, still-open tasks; re-checking
        // completion here guards the gap between the query and the worker
        // picking the job up.
        return null === $context->task->getCompletedOn();
    }
}
