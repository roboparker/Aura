<?php

declare(strict_types=1);

namespace App\Automation;

/**
 * What starts a rule (#764).
 *
 * Auto-registered through the `app.automation_trigger` tag — adding one is a
 * class here plus a client entry, no engine edit. Same drop-in shape as the
 * dashboard widgets and analytics metrics.
 *
 * A trigger has two jobs: name the **event** it listens for (so the engine can
 * cheaply skip rules that can't apply), and then decide whether this particular
 * change actually matches its configuration.
 */
interface TriggerDefinitionInterface
{
    /** Stable wire key, e.g. `task.completed`. Never change it once shipped. */
    public function type(): string;

    /** Palette label. */
    public function label(): string;

    /** One line describing when this fires, in the user's terms. */
    public function description(): string;

    /**
     * The lifecycle event this listens for — see {@see AutomationEvents}.
     *
     * The engine indexes rules by this, so a task edit only wakes the rules
     * that could possibly care.
     */
    public function event(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function configSchema(): array;

    /**
     * @param array<string, mixed> $config
     *
     * @return string|null null when valid, else a message for the user
     */
    public function validateConfig(array $config): ?string;

    /**
     * Whether this change matches.
     *
     * Called only when {@see event()} already matched, so this is the narrow
     * check — "was it moved to *this* section", not "was it moved".
     *
     * @param array<string, mixed> $config
     */
    public function matches(AutomationContext $context, array $config): bool;
}
