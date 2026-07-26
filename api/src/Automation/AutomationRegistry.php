<?php

declare(strict_types=1);

namespace App\Automation;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every registered trigger, condition, and action (#764), keyed by wire type.
 *
 * One registry rather than three, because every caller — the validator, the
 * executor, the palette endpoint — needs to look across all three kinds, and
 * three injected registries would just move the joining somewhere worse.
 */
final class AutomationRegistry
{
    /** @var array<string, TriggerDefinitionInterface> */
    private array $triggers = [];

    /** @var array<string, ConditionDefinitionInterface> */
    private array $conditions = [];

    /** @var array<string, ActionDefinitionInterface> */
    private array $actions = [];

    /**
     * @param iterable<TriggerDefinitionInterface>   $triggers
     * @param iterable<ConditionDefinitionInterface> $conditions
     * @param iterable<ActionDefinitionInterface>    $actions
     */
    public function __construct(
        #[AutowireIterator('app.automation_trigger')]
        iterable $triggers,
        #[AutowireIterator('app.automation_condition')]
        iterable $conditions,
        #[AutowireIterator('app.automation_action')]
        iterable $actions,
    ) {
        foreach ($triggers as $trigger) {
            $this->triggers[$trigger->type()] = $trigger;
        }
        foreach ($conditions as $condition) {
            $this->conditions[$condition->type()] = $condition;
        }
        foreach ($actions as $action) {
            $this->actions[$action->type()] = $action;
        }
    }

    public function trigger(string $type): ?TriggerDefinitionInterface
    {
        return $this->triggers[$type] ?? null;
    }

    public function condition(string $type): ?ConditionDefinitionInterface
    {
        return $this->conditions[$type] ?? null;
    }

    public function action(string $type): ?ActionDefinitionInterface
    {
        return $this->actions[$type] ?? null;
    }

    /** @return list<TriggerDefinitionInterface> */
    public function allTriggers(): array
    {
        return array_values($this->triggers);
    }

    /** @return list<ConditionDefinitionInterface> */
    public function allConditions(): array
    {
        return array_values($this->conditions);
    }

    /** @return list<ActionDefinitionInterface> */
    public function allActions(): array
    {
        return array_values($this->actions);
    }

    /**
     * Trigger types that listen for `$event` — the index that keeps an ordinary
     * task edit from waking every rule in the space.
     *
     * @return list<string>
     */
    public function triggerTypesForEvent(string $event): array
    {
        $types = [];
        foreach ($this->triggers as $type => $trigger) {
            if ($trigger->event() === $event) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
