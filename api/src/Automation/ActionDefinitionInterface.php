<?php

declare(strict_types=1);

namespace App\Automation;

/**
 * Something a rule does (#764).
 *
 * Auto-registered through the `app.automation_action` tag.
 *
 * **Actions are confined to the rule's board.** An implementation that resolves
 * an id from config — a section, a tag, an assignee — must verify the target
 * belongs to the same board or space and **fail closed** if not. Config is
 * user-supplied, and a rule quietly reaching into another board would be a
 * cross-tenant write.
 *
 * Implementations mutate the task but must not flush; {@see AutomationRunner}
 * owns the transaction so a part-applied rule can't leave a task half-changed.
 */
interface ActionDefinitionInterface
{
    /** Stable wire key, e.g. `task.assign`. */
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
     * Performs the action.
     *
     * @param array<string, mixed> $config
     *
     * @return string a short past-tense line for the run log, e.g.
     *                "assigned to sam@example.com" — this is what someone reads
     *                when asking why their task changed
     */
    public function execute(AutomationContext $context, array $config): string;
}
