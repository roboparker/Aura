import { useEffect, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Card, CardContent } from "@/components/ui/card";
import type { FooterResponse, FooterRow } from "./types";

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

const CustomFieldFooterRow = ({ projectId, filters, refreshKey }: Props) => {
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

  if (error || rows.length === 0) return null;

  return (
    <Card
      className="rounded-t-none border-t-0"
      data-testid="custom-field-footer-row"
    >
      <CardContent className="py-2 flex flex-wrap gap-x-6 gap-y-1 text-sm">
        {rows.map((row) => (
          <div
            key={row.definition}
            className="flex items-baseline gap-2"
            data-testid="custom-field-footer-cell"
          >
            <span className="text-muted-foreground">
              {row.label ?? `${row.name} (${row.kind})`}
            </span>
            <span className="font-medium tabular-nums">{formatValue(row)}</span>
          </div>
        ))}
      </CardContent>
    </Card>
  );
};

export default CustomFieldFooterRow;
