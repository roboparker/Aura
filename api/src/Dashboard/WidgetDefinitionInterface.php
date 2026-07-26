<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Entity\Space;
use App\Entity\User;

/**
 * One kind of dashboard widget (#759).
 *
 * Implementations are auto-registered through the `app.dashboard_widget` tag,
 * so **adding a widget is one class here plus one entry in the PWA's
 * `WIDGET_RENDERERS` map** — no migration, no route, no edit to any dashboard
 * code. Same drop-in shape as the custom-field type strategies and the MCP
 * tools, so a future module can ship its own widgets the way a Symfony bundle
 * ships services.
 *
 * Two rules an implementation must honour:
 *
 * 1. **Declare your permission category.** {@see permissionCategory()} is the
 *    only thing standing between a widget and the wrong reader; it is checked
 *    per widget on every dashboard read. Returning null means "any space
 *    member", which is a claim about the data, not a default to fall back on.
 *    `DashboardWidgetTest` pins every registered type to an explicit choice.
 * 2. **Validate your own config.** {@see configSchema()} tells the client what
 *    to render, but the client is not a trust boundary — the config JSON is
 *    user input and arrives unvalidated. {@see validateConfig()} is where it is
 *    actually checked, and no config value may ever be interpolated into SQL.
 */
interface WidgetDefinitionInterface
{
    /**
     * Stable wire key, e.g. `analytics.metric`. Stored on every instance row
     * and used to find the client renderer, so it must not change once
     * shipped — renaming it orphans every widget anyone has added.
     */
    public function type(): string;

    /** Catalog display name. */
    public function name(): string;

    /** One line in the catalog: what the widget shows, in the user's terms. */
    public function description(): string;

    /** Catalog section heading, e.g. "Analytics". */
    public function group(): string;

    /**
     * The `SpacePermission::*` category a viewer must be able to read, or null
     * when the widget shows nothing space-scoped (a notes widget, say).
     *
     * **Takes the config**, because for some widgets the answer genuinely
     * depends on it: a "metric chart" showing tracked hours and one showing
     * revenue are the same widget type with very different audiences. Pinning
     * such a type to a single category would either leak the money or hide the
     * hours.
     *
     * Called with an empty config to probe the catalog, where no instance
     * exists yet. A config naming something unreadable or unknown should fail
     * closed — return the stricter category rather than null.
     *
     * Admin-reserved categories are resolved through `canByExplicitGrant()`
     * upstream — see {@see \App\Dashboard\WidgetAccess}.
     *
     * @param array<string, mixed> $config
     */
    public function permissionCategory(array $config = []): ?string;

    /**
     * Starting size in grid units: width 1-4 columns, height in rows.
     *
     * @return array{w: int, h: int}
     */
    public function defaultSize(): array;

    /**
     * Describes this widget's settings so the client can render an editor
     * without knowing the widget: a list of fields, each with a key, a control
     * type, a label, and any options.
     *
     * @return list<array<string, mixed>>
     */
    public function configSchema(): array;

    /**
     * Validates a config blob from the client.
     *
     * @param array<string, mixed> $config
     *
     * @return string|null null when valid, else a message for the user
     */
    public function validateConfig(array $config): ?string;

    /**
     * The widget's payload, fetched per instance so widgets load in parallel
     * and a slow one can't block the rest of the page.
     *
     * Called only after the viewer has passed {@see permissionCategory()}, but
     * an implementation still scopes its own queries to `$space` — the gate
     * says *whether* you may look, not *at what*.
     *
     * Must tolerate a config pointing at something since deleted (a removed
     * board, an archived engagement) by returning an empty payload rather than
     * throwing; one stale widget shouldn't read as a broken dashboard.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function data(Space $space, array $config, User $user): array;
}
