import { useEffect, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";
import TaskTableColumns from "@/components/projects/TaskTableColumns";
import type { ListColumn } from "@/components/projects/listColumns";
import type {
  FooterBreakdownEntry,
  FooterResponse,
  FooterRow,
} from "./types";

/**
 * Renders the aggregated footer row for a project's task list. Fetches
 * `/projects/{id}/custom_field_footers` whenever the projectId or
 * `filters` query string changes, so the footer reflects the same row
 * set as the filtered task list above it.
 *
 * `filters` is the encoded querystring (no leading `?`) — the parent
 * passes through whatever it sent to `/tasks`. The endpoint only
 * inspects a whitelist of params; everything else is ignored.
 */
interface Props {
  projectId: string;
  filters?: string;
  /** Bump to force a re-fetch (e.g. after a task value is edited inline). */
  refreshKey?: number;
  /** Optional leading label (e.g. "Grand total") — also switches to a
   *  standalone rounded card instead of the table-attached style. */
  heading?: string;
  className?: string;
  /** When set, only footers for these definition IRIs are shown — keeps the
   *  list footer in step with the list's `visibility`-filtered columns. */
  visibleDefinitions?: string[];
  /** When set, render a column-aligned bar (a `table-fixed` table sharing the
   *  section tables' columns via {@link TaskTableColumns}) so each total sits
   *  under its custom-field column — in the same (possibly reordered) order as
   *  the table above. Used for the per-section + grand-total bars. */
  columns?: ListColumn[];
  /** Stronger styling for the grand-total bars. */
  prominent?: boolean;
  /** Render as a bare table (no card wrapper) to sit inside a section table's
   *  scroll container as a flush, column-aligned footer. */
  flush?: boolean;
  /** Render as a single `<tr>` (no table/colgroup wrapper) so the totals can
   *  live inside a shared table body, e.g. one continuous list with section
   *  rows. Requires `columns`; the parent owns the `<table>` + `<colgroup>`. */
  asRow?: boolean;
}

const formatValue = (row: FooterRow): string => {
  const v = row.value;
  if (v === null || v === undefined) return "—";
  if (typeof v === "number") {
    return row.kind === "count" ? String(Math.round(v)) : formatNumber(v);
  }
  if (typeof v === "string") return v;
  if (typeof v === "object" && "amount" in v && "currency" in v) {
    const amount = (v as { amount: number }).amount;
    const currency = (v as { currency: string }).currency;
    try {
      return new Intl.NumberFormat(undefined, {
        style: "currency",
        currency,
        // The API stores money in minor units; convert back via the
        // currency's fraction-digit count so a JPY amount of 1000 lands
        // as ¥1,000 and a USD amount of 1000 lands as $10.00.
      }).format(amount / Math.pow(10, fractionDigitsFor(currency)));
    } catch {
      return `${amount} ${currency}`;
    }
  }
  return String(v);
};

const fractionDigitsFor = (currency: string): number => {
  try {
    const fmt = new Intl.NumberFormat(undefined, { style: "currency", currency });
    return fmt.resolvedOptions().maximumFractionDigits ?? 2;
  } catch {
    return 2;
  }
};

const formatNumber = (v: number): string => {
  // Render avg with two decimals, integers without — same rule the PWA
  // uses for the budget line on the dashboard.
  return Number.isInteger(v)
    ? v.toLocaleString()
    : v.toLocaleString(undefined, { maximumFractionDigits: 2 });
};

const isBreakdown = (v: unknown): v is FooterBreakdownEntry[] =>
  Array.isArray(v) &&
  v.every(
    (e) =>
      e !== null &&
      typeof e === "object" &&
      "count" in e &&
      "label" in e,
  );

/**
 * Renders a footer value. Scalar aggregations (sum/avg/min/max/count, money)
 * go through {@link formatValue}; a select `breakdown` renders as a small
 * stacked per-option "label count" list.
 */
const FooterValue = ({ row }: { row: FooterRow }) => {
  if (row.kind === "breakdown" && isBreakdown(row.value)) {
    if (row.value.length === 0) return <>—</>;
    return (
      <span className="flex flex-col gap-0.5">
        {row.value.map((entry) => (
          <span
            key={entry.key}
            className="flex items-baseline justify-between gap-3 whitespace-nowrap"
          >
            <span className="font-normal text-muted-foreground">
              {entry.label}
            </span>
            <span className="tabular-nums">{entry.count}</span>
          </span>
        ))}
      </span>
    );
  }
  return <>{formatValue(row)}</>;
};

const CustomFieldFooterRow = ({
  projectId,
  filters,
  refreshKey,
  heading,
  className,
  visibleDefinitions,
  columns,
  prominent,
  flush,
  asRow,
}: Props) => {
  const [rows, setRows] = useState<FooterRow[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let aborted = false;
    const qs = filters && filters.length > 0 ? `?${filters}` : "";
    void fetch(
      `${ENTRYPOINT}/projects/${projectId}/custom_field_footers${qs}`,
      {
        credentials: "include",
        headers: { Accept: "application/json" },
      },
    )
      .then(async (res) => {
        if (!res.ok) throw new Error("Failed to load footers.");
        const data: FooterResponse = await res.json();
        if (!aborted) setRows(data.footers);
      })
      .catch((err) => {
        if (!aborted)
          setError(err instanceof Error ? err.message : "Footer fetch failed.");
      });
    return () => {
      aborted = true;
    };
  }, [projectId, filters, refreshKey]);

  const visibleRows = visibleDefinitions
    ? rows.filter((row) => visibleDefinitions.includes(row.definition))
    : rows;

  if (error || visibleRows.length === 0) return null;

  // Column-aligned aggregate bar: a table-fixed table sharing the section
  // tables' columns, so each aggregate lands under its custom-field column.
  // Each cell stacks the aggregate type over its value (no field name — the
  // column header conveys that). Used for both per-section and grand totals.
  if (columns) {
    const valueByDef = new Map(rows.map((row) => [row.definition, row]));
    const cells = (
      <>
        {/* checkbox — fixed leading column, mirroring the task table. */}
        <td className="px-3 py-2" />
        {columns.map((column) => {
          // Only custom-field columns can carry an aggregate; built-ins
          // (Task / Due / Assignees / Tags) render an empty spacer so the
          // totals stay aligned under their column after any reorder.
          const def = column.definition;
          const row = def ? valueByDef.get(def["@id"]) : undefined;
          if (!def) {
            return <td key={column.key} className="px-2 py-2" />;
          }
          return (
            <td
              key={column.key}
              className="px-2 py-2 align-top"
              data-testid="custom-field-footer-cell"
            >
              {row && (
                <div className="flex flex-col leading-tight">
                  <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    {row.label ?? row.kind}
                  </span>
                  <span className="tabular-nums font-semibold">
                    <FooterValue row={row} />
                  </span>
                </div>
              )}
            </td>
          );
        })}
        <td className="px-2 py-2" />
      </>
    );

    // As a single row inside a shared table body (the parent owns the table
    // + colgroup), so per-section totals sit in one continuous list.
    if (asRow) {
      return (
        <tr
          className={cn(
            "border-t",
            prominent ? "bg-muted/60 font-semibold" : "bg-muted/20",
          )}
          data-testid="custom-field-footer-row"
        >
          {cells}
        </tr>
      );
    }

    // Flush: a bare table that lives inside a section table's scroll container,
    // lining up with that table's columns and scrolling with it.
    if (flush) {
      return (
        <table
          className="w-full table-fixed border-t text-sm"
          data-testid="custom-field-footer-row"
        >
          <TaskTableColumns columns={columns} />
          <tbody>
            <tr>{cells}</tr>
          </tbody>
        </table>
      );
    }

    // Standalone grand-total bar.
    return (
      <div
        className={cn(
          "overflow-hidden rounded-lg border",
          prominent && "border-foreground/25 bg-muted/70",
          className,
        )}
        data-testid="custom-field-footer-row"
      >
        <div className="overflow-x-auto">
          <table className="w-full table-fixed text-sm">
            <TaskTableColumns columns={columns} />
            <tbody>
              <tr>{cells}</tr>
            </tbody>
          </table>
        </div>
      </div>
    );
  }

  return (
    <Card
      className={cn(
        // Per-section footer (no heading) sits flush inside the section
        // table's rounded, clipped wrapper — just a top divider, no border or
        // rounding of its own. Standalone (heading) footers keep their card.
        heading ? "rounded-lg" : "rounded-none border-0 border-t",
        className,
      )}
      data-testid="custom-field-footer-row"
    >
      <CardContent className="py-2 flex flex-wrap items-baseline gap-x-6 gap-y-1 text-sm">
        {heading && (
          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            {heading}
          </span>
        )}
        {visibleRows.map((row) => (
          <div
            key={row.definition}
            className="flex items-baseline gap-2"
            data-testid="custom-field-footer-cell"
          >
            <span className="text-muted-foreground">
              {row.label ?? `${row.name} (${row.kind})`}
            </span>
            <span className="font-medium tabular-nums">
              <FooterValue row={row} />
            </span>
          </div>
        ))}
      </CardContent>
    </Card>
  );
};

export default CustomFieldFooterRow;
