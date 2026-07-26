<?php

declare(strict_types=1);

namespace App\Automation\Condition;

use App\Automation\AutomationContext;
use App\Automation\ConditionDefinitionInterface;

/**
 * Passes when the task falls due within the next N days.
 *
 * Counts from today inclusive, and an already-overdue task counts as due —
 * "remind me about anything due this week" should not skip the thing that was
 * due yesterday.
 */
final class DueWithinCondition implements ConditionDefinitionInterface
{
    private const MAX_DAYS = 365;

    public function type(): string
    {
        return 'task.due_within';
    }

    public function label(): string
    {
        return 'Due within';
    }

    public function description(): string
    {
        return 'Continues only if the task is due in the next few days.';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'days', 'type' => 'number', 'label' => 'Days', 'min' => 0, 'max' => self::MAX_DAYS],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $days = $config['days'] ?? null;

        return is_int($days) && $days >= 0 && $days <= self::MAX_DAYS
            ? null
            : sprintf('Enter a number of days between 0 and %d.', self::MAX_DAYS);
    }

    public function passes(AutomationContext $context, array $config): bool
    {
        $due = $context->task->getDueDate();
        if (null === $due) {
            return false;
        }

        $days = is_int($config['days'] ?? null) ? $config['days'] : 0;
        $cutoff = (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $days));

        return $due <= $cutoff;
    }
}
