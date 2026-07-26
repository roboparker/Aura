<?php

declare(strict_types=1);

namespace App\Automation;

/**
 * A gate in a rule (#764). Its downstream subtree runs only when it passes.
 *
 * Auto-registered through the `app.automation_condition` tag.
 *
 * Conditions are evaluated against the task's **current** state, not the change
 * snapshot — "is this task overdue" is a question about the task, where "did
 * the due date just change" is a trigger's job. Keeping that split means a
 * condition behaves the same wherever it's hung in the graph.
 */
interface ConditionDefinitionInterface
{
    /** Stable wire key, e.g. `task.has_tag`. */
    public function type(): string;

    public function label(): string;

    public function description(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function configSchema(): array;

    /**
     * @param array<string, mixed> $config
     */
    public function validateConfig(array $config): ?string;

    /**
     * Must not throw on a config pointing at something since deleted — a
     * removed tag or section should read as "does not match" rather than
     * breaking every run of the rule.
     *
     * @param array<string, mixed> $config
     */
    public function passes(AutomationContext $context, array $config): bool;
}
