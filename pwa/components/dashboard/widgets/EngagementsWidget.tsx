import Link from "next/link";
import { formatMoney } from "@/lib/invoiceTypes";
import type { WidgetProps } from "./registry";

interface EngagementRow {
  id: string;
  name: string;
  client: string | null;
  currency: string | null;
  budgetType: "hours" | "fees" | null;
  budgetAmount: number | null;
  budgetSpent: number | null;
  percent: number | null;
}

/** "1.0 / 2.0 h" or "$500.00 / $2,000.00", matching the /projects page. */
const budgetLabel = (row: EngagementRow): string => {
  if (!row.budgetType || row.budgetAmount === null) return "No budget";
  const spent = row.budgetSpent ?? 0;
  if (row.budgetType === "hours") {
    return `${(spent / 60).toFixed(1)} / ${(row.budgetAmount / 60).toFixed(1)} h`;
  }
  return `${formatMoney(spent, row.currency ?? "USD")} / ${formatMoney(
    row.budgetAmount,
    row.currency ?? "USD",
  )}`;
};

/** Amber past 80%, red past 100% — same thresholds the /projects page uses. */
const barTone = (percent: number): string =>
  percent >= 100 ? "bg-red-500" : percent >= 80 ? "bg-amber-500" : "bg-emerald-500";

const EngagementsWidget = ({ data, isLoading }: WidgetProps) => {
  if (isLoading) {
    return <div className="h-full animate-pulse rounded-md bg-muted/40" />;
  }

  const rows = ((data ?? {}) as { rows?: EngagementRow[] }).rows ?? [];
  if (rows.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-sm text-muted-foreground">No active engagements.</p>
      </div>
    );
  }

  return (
    <ul className="space-y-2.5">
      {rows.map((row) => (
        <li key={row.id}>
          <div className="flex items-baseline justify-between gap-2">
            <Link
              href="/projects"
              className="truncate text-sm font-medium hover:underline"
            >
              {row.name}
            </Link>
            <span className="shrink-0 text-xs tabular-nums text-muted-foreground">
              {budgetLabel(row)}
            </span>
          </div>
          {row.client && (
            <p className="truncate text-xs text-muted-foreground">{row.client}</p>
          )}
          {row.percent !== null && (
            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
              <div
                className={`h-full ${barTone(row.percent)}`}
                style={{ width: `${Math.min(100, row.percent)}%` }}
              />
            </div>
          )}
        </li>
      ))}
    </ul>
  );
};

export default EngagementsWidget;
