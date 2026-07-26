# Dashboard widgets

The configurable space dashboard at `/dashboard` is a grid of widgets. The
widget system is an **extension point**: another module adds a widget the way a
Symfony bundle adds a service — drop in a class, and it appears.

Adding a widget is **one PHP class and one TypeScript entry**. No migration, no
route, no edit to any dashboard code.

- Issue: [#759](https://github.com/roboparker/Aura/issues/759)
- Backend: `api/src/Dashboard/`
- Frontend: `pwa/components/dashboard/`

## How it fits together

```
WidgetDefinitionInterface        ← you write this (PHP)
  ↓  app.dashboard_widget tag
WidgetRegistry                   ← collects every definition
  ↓
DashboardController              ← catalog, layout, per-widget data
  ↓  HTTP
WIDGET_RENDERERS                 ← you write one entry here (TS)
  ↓
WidgetFrame                      ← chrome, drag handle, data fetch
```

The two halves are joined only by the `type` string. Neither imports the other,
which is what lets them be deployed independently.

## Adding a widget

### 1. The server definition

Create a class in `api/src/Dashboard/Widget/` implementing
`WidgetDefinitionInterface`. The `app.dashboard_widget` tag is applied
automatically by the `_instanceof` block in `api/config/services.yaml` — there
is nothing to register.

```php
final class OverdueInvoicesWidget implements WidgetDefinitionInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function type(): string { return 'invoices.overdue'; }
    public function name(): string { return 'Overdue invoices'; }
    public function description(): string { return 'Invoices past their due date.'; }
    public function group(): string { return 'Billing'; }

    // Who may see it. See "Permissions" below — this is the important one.
    public function permissionCategory(array $config = []): string
    {
        return SpacePermission::INVOICES;
    }

    public function defaultSize(): array { return ['w' => 2, 'h' => 2]; }

    // Describes the settings form. The client renders it generically.
    public function configSchema(): array
    {
        return [['key' => 'limit', 'type' => 'number', 'label' => 'Rows', 'min' => 1, 'max' => 20]];
    }

    public function validateConfig(array $config): ?string
    {
        $limit = $config['limit'] ?? 5;
        return is_int($limit) && $limit >= 1 && $limit <= 20
            ? null
            : 'Rows must be between 1 and 20.';
    }

    public function data(Space $space, array $config, User $user): array
    {
        // Scope to $space. Return whatever your component needs.
        return ['rows' => []];
    }
}
```

### 2. The client renderer

Add a component in `pwa/components/dashboard/widgets/` and one entry in
`registry.tsx`, keyed by the same `type`:

```tsx
export const WIDGET_RENDERERS: Record<string, WidgetRenderer> = {
  // …
  "invoices.overdue": { component: OverdueInvoicesWidget, icon: FileWarning, fetchesData: true },
};
```

The component receives `{ widget, data, isLoading }`. `data` is exactly what
your `data()` returned, typed `unknown` — narrow it in the component. Set
`fetchesData: false` if everything you show lives in the config, and the frame
will skip the request entirely.

That's the whole task. The widget now appears in the catalog, can be added,
configured, resized, dragged, and removed.

## Permissions

**This is the part to get right.** Every read path — the layout listing, the
per-instance data endpoint, and the catalog — goes through
`WidgetAccess::canRead()`, so a widget can't be gated one way in a list and
another way when fetched directly.

Rules:

- **Declare a category.** Returning `null` means "any space member" and is a
  claim that the widget shows nothing space-scoped. It is not a default to fall
  back on when unsure. `DashboardWidgetTest` fails if a registered widget names
  a category that doesn't exist.
- **Admin-reserved categories are handled for you.** `invoices` and `api_keys`
  are in `SpacePermission::ADMIN_RESERVED`, and `WidgetAccess` routes those
  through `canByExplicitGrant()` rather than plain `can()`. This matters because
  `can()` carries a rule where *a member with no assigned roles is
  unrestricted* — on a revenue widget that would show every member of every
  space the money. Don't call the resolver yourself; use `WidgetAccess`.
- **The category may depend on the config.** `permissionCategory()` receives
  the instance's config, because a metric chart of tracked hours and one of
  revenue are the same widget type with different audiences. If your config can
  point at things with different sensitivities, branch on it — and **fail
  closed** when the config names something unknown, returning the stricter
  category rather than `null`.
- **A widget the caller can't read is omitted**, not errored. A member without
  invoicing access sees a shorter dashboard, not a broken one.

Editing the layout (add, move, resize, configure, remove) is **space admin
only**, since the layout is shared by everyone in the space.

## Config is untrusted

`config` is JSON supplied by the client. `validateConfig()` is where it is
actually checked — the config editor is a convenience, not a trust boundary.
Never interpolate a config value into SQL.

Only space admins can write a config, so `validateConfig()` doesn't need the
user: an admin can already read everything in their own space.

## Degrading, not breaking

A widget type present in the database but absent from the registry — module
removed, or a client older than the API — renders as a labelled placeholder
that can still be removed:

- The server keeps it in the layout with `available: false`. Dropping the row
  would look like data loss to an admin; throwing would take a whole dashboard
  down over one stale widget.
- Its data endpoint returns 404 rather than fataling on a missing definition.
- Its config can't be edited (there's no schema to validate against), but it can
  be deleted — otherwise it'd be stuck there forever.

`data()` should tolerate a config pointing at something since deleted (a removed
board, an archived engagement) by returning an empty payload rather than
throwing.

## Why data is per instance

`GET /dashboard-widgets/{id}/data` serves one widget. The alternative — one fat
`/dashboard` response resolving every widget server-side — is a single round
trip, but the page would only be as fast as its slowest widget, and one badly
behaved third-party widget could break the whole response. Per-instance means
parallel loads, independent error states, and no new route per widget type.

## Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/spaces/{id}/dashboard` | any member (filtered per widget) |
| GET | `/spaces/{id}/dashboard/catalog` | any member (filtered) |
| POST | `/spaces/{id}/dashboard/widgets` | space admin |
| PATCH | `/dashboard-widgets/{id}` | space admin |
| DELETE | `/dashboard-widgets/{id}` | space admin |
| POST | `/spaces/{id}/dashboard/reorder` | space admin |
| GET | `/dashboard-widgets/{id}/data` | any member who can read the widget |

Reorder takes the whole order at once, because a drag renumbers several widgets
and sending that as N patches would scramble the layout if one failed.

## Storage

One `dashboard_widget` row per placed widget: `space`, `type`, `config`,
`position`, `width`, `height`. There is no parent "dashboard" entity — a space's
dashboard is its set of widgets.

`type` is a plain string, not an enum, precisely so a module can add one without
a migration.

## Tests

`api/tests/Api/DashboardWidgetTest.php`. If you add a widget, the coverage test
already forces it to declare a valid category. Add a permission test of your own
if your widget shows anything sensitive.
