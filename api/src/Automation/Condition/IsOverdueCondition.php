<?php

declare(strict_types=1);

namespace App\Automation\Condition;

use App\Automation\AutomationContext;
use App\Automation\ConditionDefinitionInterface;

/**
 * Passes when the task is past its due date and still open.
 *
 * A task with no due date is never overdue — it can't be late for a deadline it
 * doesn't have, and treating null as "overdue" would fire rules across an
 * entire undated backlog.
 */
final class IsOverdueCondition implements ConditionDefinitionInterface
{
    public function type(): string
    {
        return 'task.is_overdue';
    }

    public function label(): string
    {
        return 'Task is overdue';
    }

    public function description(): string
    {
        return 'Continues only if the task is past its due date and not yet done.';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function validateConfig(array $config): ?string
    {
        return null;
    }

    public function passes(AutomationContext $context, array $config): bool
    {
        $task = $context->task;
        $due = $task->getDueDate();
        if (null === $due || null !== $task->getCompletedOn()) {
            return false;
        }

        return $due < new \DateTimeImmutable('today');
    }
}
