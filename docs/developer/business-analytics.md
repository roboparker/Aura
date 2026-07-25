# Business analytics

Trend charts over a space's own money and time: what was invoiced, what was
collected, how many hours were tracked, and how much billable work is still
sitting uninvoiced.

> Not to be confused with [analytics.md](analytics.md), which covers **Umami** —
> product usage analytics about how people use Madori. This document is about
> the numbers a *user* sees about their own business.

## Surfaces

| Surface | Where |
| ------- | ----- |
| REST    | `GET /spaces/{id}/analytics` (`App\Controller\AnalyticsController`) |
| PWA     | the **Dashboard** tab on `/reports` (`pwa/components/reports/AnalyticsDashboard.tsx`) |
| MCP     | `get_analytics` — see [mcp-server.md](mcp-server.md#analytics) |

## The metrics

Each is one class under `api/src/Analytics/Metric/`, implementing
`AnalyticsMetricInterface`.

| Key | What it counts | Category | Unit |
| --- | -------------- | -------- | ---- |
| `invoiced` | `invoice.total` by issue date, statuses sent/paid/overdue | `invoices` | money |
| `collected` | `invoice_payment.amount` by payment date | `invoices` | money |
| `tracked_time` | `time_entry.duration_seconds` by start date | `time_entries` | seconds |
| `billable_time` | the same, `billable = true` | `time_entries` | seconds |
| `unbilled_value` | billable, un-invoiced time priced at its snapshotted rate | `time_entries` | money |

Deliberate choices worth knowing:

- **Draft and void invoices are excluded from `invoiced`.** A draft isn't a
  claim on anyone and a void one has been withdrawn; counting either inflates
  revenue.
- **`invoiced` and `collected` are separate metrics on separate dates.** An
  invoice raised in January and paid in March lands in January for one and March
  for the other — the gap between the two curves *is* the cash-flow story.
- **`unbilled_value` prices time exactly the way invoice generation does**
  (`ROUND(hours, 2) × rate`, rounded per row), so the dashboard and the eventual
  invoice agree. Entries with no rate contribute nothing rather than counting as
  free work.
- **`unbilled_value` rides `time_entries`, not `invoices`.** It's derived from
  tracked time its owner can already see, not from any issued document.

### Money is minor units

Every money value is an integer in **minor currency units** (cents), and time is
an integer count of **seconds**. The unit travels with the metric (`unit`) rather
than being inferred from the key, because getting it wrong shows a 100× error.

### Multi-currency

A metric returns one series **per currency** — a space invoicing in both USD and
EUR can't meaningfully sum them into a single number, so it doesn't try. Non-money
metrics return a single series with `currency: null`.

## Permissions

**Per metric, not per endpoint.** Each metric declares a
`permissionCategory()`, and the controller resolves it before running the query.
Metrics the caller can't read are **omitted** from the response, not errored, so
a mixed dashboard degrades to the parts they're entitled to — a member with time
access but no invoicing access sees a shorter dashboard rather than a locked
page.

> ⚠️ **The trap.** `invoices` is in `SpacePermission::ADMIN_RESERVED`, so it must
> be resolved with `SpacePermissionResolver::canByExplicitGrant()`. Plain `can()`
> carries the "a member with no assigned roles is unrestricted" back-compat rule,
> and using it here would hand every member of every space the company's revenue.
> `AnalyticsTest::testPlainMemberIsDeniedMoneyMetricsButKeepsTimeOnes` is the
> regression net.

Requesting a denied metric by name via `?metrics=` doesn't bypass anything — the
filter narrows the set, the permission check still runs.

## Aggregation

There is **no snapshot table**. Every request aggregates live in SQL over the
window, which keeps the numbers exact and means no backfill or staleness to
reason about. If this becomes a cost problem, a nightly rollup is the natural
next step — the metric interface is already the seam for it.

`App\Analytics\AnalyticsQuery::series()` is the shared plumbing: bucket a date
column with `date_trunc`, sum a value column, scope to a space, group per
currency.

> The `interval` never reaches SQL as user input. `date_trunc` takes its unit as
> a string, so interpolating a request value there would be an injection; the
> request value is mapped through a fixed literal table first, and rejected with
> a 422 if it isn't one of `day` / `week` / `month`.

Ranges are capped at `MAX_RANGE_DAYS` (1100, ~3 years of months). Each metric
scans its table over the window, so an unbounded range is a denial of service on
ourselves.

Periods with no rows are **absent** from the response — the series is `GROUP BY`
output, not a dense calendar. The chart honours that by leaving a gap in the line
rather than drawing a zero, because a zero asserts "earned nothing" where the
truth is "no data".

## Adding a metric

One class, no wiring:

```php
final class RefundedMetric implements AnalyticsMetricInterface
{
    public function key(): string { return 'refunded'; }
    public function label(): string { return 'Refunded'; }
    public function permissionCategory(): string { return SpacePermission::INVOICES; }
    public function unit(): string { return 'money'; }

    public function series(Connection $db, Space $space, \DateTimeImmutable $from, \DateTimeImmutable $to, string $interval): array
    {
        return AnalyticsQuery::series(
            $db,
            fromClause: 'refund',
            spacePredicate: 'space_id = :space',
            dateExpr: 'refunded_on',
            valueExpr: 'amount',
            currencyExpr: 'currency',
            spaceId: (string) $space->getId(),
            from: $from, to: $to, interval: $interval,
        );
    }
}
```

`_instanceof` in `services.yaml` tags it `app.analytics_metric`, the registry
picks it up, and it appears on all three surfaces at once — the same
auto-registration the MCP tools and custom-field strategies use.

Two rules the interface exists to enforce:

1. **Aggregate in SQL.** Never load rows into PHP to sum them; these tables grow
   without bound.
2. **Declare a permission category**, and make sure it's the one the REST
   `security:` expressions use for the same data. A metric is a new way to read
   existing rows, not a new thing to secure.

The SQL fragments a metric passes (`fromClause`, `dateExpr`, `valueExpr`,
`extraWhere`, …) are **written by the metric, never taken from the request**.
Anything derived from a request travels as a bound parameter.

## Frontend

`AnalyticsDashboard` renders one card per metric returned — headline total,
period-over-period trend, and an area chart. It does **no permission branching**
of its own; it renders what it's given, which is why the server omits rather than
errors.

Recharts is behind a `next/dynamic` import so it stays out of the initial bundle;
the whole app shouldn't pay for a library one tab uses.

Formatting and reshaping live in `pwa/lib/analyticsTypes.ts` (unit-tested in
`analyticsTypes.test.ts`) rather than in the components — merging per-currency
series onto a shared axis and converting minor units are exactly the logic worth
testing without a DOM.

Series colours are the Okabe–Ito palette: colour-blind safe, and legible on both
the light and dark grounds.

## Tests

| What | Where |
| ---- | ----- |
| Permissions, validation, aggregation | `App\Tests\Api\AnalyticsTest` |
| MCP tool + token-scope gate | `App\Tests\Api\McpTest` |
| Formatting and series merging | `pwa/lib/analyticsTypes.test.ts` |
