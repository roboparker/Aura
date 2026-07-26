<?php

declare(strict_types=1);

namespace App\Automation\Action;

use App\Automation\ActionDefinitionInterface;
use App\Automation\AutomationContext;

/**
 * Sets or clears the due date, relative to when the rule runs.
 *
 * Relative rather than absolute on purpose: a rule saying "due in 3 days" stays
 * useful forever, where a fixed date would silently become a rule that sets
 * everything to a day in the past.
 */
final class SetDueDateAction implements ActionDefinitionInterface
{
    private const MAX_DAYS = 365;

    public function type(): string
    {
        return 'task.set_due_date';
    }

    public function label(): string
    {
        return 'Set due date';
    }

    public function description(): string
    {
        return 'Sets the due date a number of days from now, or clears it.';
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'mode',
                'type' => 'select',
                'label' => 'Due date',
                'options' => [
                    ['value' => 'in_days', 'label' => 'A number of days from now'],
                    ['value' => 'clear', 'label' => 'Clear the due date'],
                ],
            ],
            ['key' => 'days', 'type' => 'number', 'label' => 'Days from now', 'min' => 0, 'max' => self::MAX_DAYS],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $mode = $config['mode'] ?? 'in_days';
        if (!in_array($mode, ['in_days', 'clear'], true)) {
            return 'Choose whether to set or clear the due date.';
        }
        if ('clear' === $mode) {
            return null;
        }

        $days = $config['days'] ?? null;

        return is_int($days) && $days >= 0 && $days <= self::MAX_DAYS
            ? null
            : sprintf('Enter a number of days between 0 and %d.', self::MAX_DAYS);
    }

    public function execute(AutomationContext $context, array $config): string
    {
        if ('clear' === ($config['mode'] ?? 'in_days')) {
            $context->task->setDueDate(null);

            return 'cleared the due date';
        }

        $days = is_int($config['days'] ?? null) ? max(0, min(self::MAX_DAYS, $config['days'])) : 0;
        $due = (new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $days));
        $context->task->setDueDate($due);

        return sprintf('set the due date to %s', $due->format('Y-m-d'));
    }
}
