<?php

declare(strict_types=1);

namespace App\Automation\Condition;

use App\Automation\AutomationContext;
use App\Automation\ConditionDefinitionInterface;

/**
 * Passes on whether the task has anyone on it.
 *
 * One condition with an invert flag rather than two classes: "is assigned" and
 * "is unassigned" share every line except the negation, and splitting them
 * would mean fixing bugs twice.
 */
final class IsAssignedCondition implements ConditionDefinitionInterface
{
    public function type(): string
    {
        return 'task.is_assigned';
    }

    public function label(): string
    {
        return 'Task assignment';
    }

    public function description(): string
    {
        return 'Continues depending on whether anyone is assigned.';
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'state',
                'type' => 'select',
                'label' => 'Assignment',
                'options' => [
                    ['value' => 'assigned', 'label' => 'Someone is assigned'],
                    ['value' => 'unassigned', 'label' => 'Nobody is assigned'],
                ],
            ],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $state = $config['state'] ?? 'assigned';

        return in_array($state, ['assigned', 'unassigned'], true)
            ? null
            : 'Choose assigned or unassigned.';
    }

    public function passes(AutomationContext $context, array $config): bool
    {
        $hasAssignee = $context->task->getAssignees()->count() > 0;

        return 'unassigned' === ($config['state'] ?? 'assigned') ? !$hasAssignee : $hasAssignee;
    }
}
