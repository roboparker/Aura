import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { BarChart3, Download } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGet } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { formatMoney } from "@/lib/invoiceTypes";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const GROUP_BYS = [
  { value: "engagement", label: "Engagement" },
  { value: "client", label: "Client" },
  { value: "category", label: "Category" },
  { value: "person", label: "Person" },
] as const;

interface SummaryRow {
  key: string;
  label: string;
  seconds: number;
  hours: number;
  billableSeconds: number;
  billableHours: number;
  amounts: Record<string, number>;
  costs: Record<string, number>;
  profit: Record<string, number>;
}

interface SummaryReport {
  groupBy: string;
  from: string | null;
  to: string | null;
  rows: SummaryRow[];
  totals: {
    seconds: number;
    hours: number;
    billableSeconds: number;
    billableHours: number;
    amounts: Record<string, number>;
    costs: Record<string, number>;
    profit: Record<string, number>;
  };
}

interface UninvoicedRow {
  engagementId: string;
  engagement: string;
  client: string | null;
  entryCount: number;
  seconds: number;
  hours: number;
  amount: number;
  currency: string;
}

/** "$123.45" / "$123.45 · €50.00" for a per-currency amount map. */
const amountsLabel = (amounts: Record<string, number>): string => {
  const parts = Object.entries(amounts).map(([currency, minor]) =>
    formatMoney(minor, currency),
  );
  return parts.length > 0 ? parts.join(" · ") : "—";
};

/** First day of the current month, as a date-input value. */
const monthStart = (): string => {
  const now = new Date();
  return `${now.getFullYear()}-${(now.getMonth() + 1).toString().padStart(2, "0")}-01`;
};

const todayInput = (): string => {
  const now = new Date();
  const pad = (n: number) => n.toString().padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};

/**
 * Billing reports (#647): a time summary (period + group-by) and the
 * per-engagement uninvoiced pool, each with a CSV export mirroring the same
 * filters server-side.
 */
const ReportsPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();

  const [tab, setTab] = useState<"summary" | "uninvoiced">("summary");
  const [from, setFrom] = useState(monthStart);
  const [to, setTo] = useState(todayInput);
  const [groupBy, setGroupBy] = useState("engagement");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceId = activeSpace?.id ?? null;
  const canView = can("invoices", "read");

  const summaryParams = new URLSearchParams({
    ...(from ? { from } : {}),
    ...(to ? { to } : {}),
    groupBy,
  });
  const summaryQuery = useQuery({
    queryKey: ["report_summary", spaceId, from, to, groupBy],
    enabled: isAuthenticated && !!spaceId && canView && tab === "summary",
    queryFn: () =>
      apiGet<SummaryReport>(
        `/spaces/${spaceId}/reports/time-summary?${summaryParams.toString()}`,
        { errorMessage: "Failed to load the report." },
      ),
  });

  const uninvoicedQuery = useQuery({
    queryKey: ["report_uninvoiced", spaceId],
    enabled: isAuthenticated && !!spaceId && canView && tab === "uninvoiced",
    queryFn: () =>
      apiGet<{ rows: UninvoicedRow[] }>(`/spaces/${spaceId}/reports/uninvoiced`, {
        errorMessage: "Failed to load the report.",
      }),
  });

  const downloadCsv = async () => {
    if (!spaceId) return;
    const path =
      tab === "summary"
        ? `/spaces/${spaceId}/reports/time-summary?${summaryParams.toString()}&format=csv`
        : `/spaces/${spaceId}/reports/uninvoiced?format=csv`;
    try {
      const res = await fetch(path, { credentials: "include", headers: { Accept: "text/csv" } });
      if (!res.ok) {
        setError("Could not build the export.");
        return;
      }
      const blob = await res.blob();
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = tab === "summary" ? `time-summary-by-${groupBy}.csv` : "uninvoiced.csv";
      document.body.appendChild(a);
      a.click();
      a.remove();
    } catch {
      setError("Could not build the export.");
    }
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  const summary = summaryQuery.data ?? null;
  const uninvoiced = uninvoicedQuery.data?.rows ?? [];

  return (
    <>
      <Head>
        <title>Reports — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-4xl">
          <PageHeader
            title="Reports"
            icon={<BarChart3 className="size-6 text-violet-600 dark:text-violet-400" />}
          />

          {!canView ? (
            <Alert>
              <AlertDescription>
                Reports are limited to members with invoicing access.
              </AlertDescription>
            </Alert>
          ) : (
            <>
              {error && (
                <Alert variant="destructive" className="mb-4">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="inline-flex rounded-md border p-0.5" role="tablist">
                  <Button
                    variant={tab === "summary" ? "secondary" : "ghost"}
                    size="sm"
                    role="tab"
                    aria-selected={tab === "summary"}
                    onClick={() => setTab("summary")}
                  >
                    Time summary
                  </Button>
                  <Button
                    variant={tab === "uninvoiced" ? "secondary" : "ghost"}
                    size="sm"
                    role="tab"
                    aria-selected={tab === "uninvoiced"}
                    onClick={() => setTab("uninvoiced")}
                  >
                    Uninvoiced
                  </Button>
                </div>
                <Button variant="outline" size="sm" onClick={() => void downloadCsv()}>
                  <Download className="h-4 w-4" /> Export CSV
                </Button>
              </div>

              {tab === "summary" && (
                <>
                  <div className="mb-4 flex flex-wrap items-end gap-3">
                    <div className="space-y-1.5">
                      <Label htmlFor="rep-from">From</Label>
                      <Input
                        id="rep-from"
                        type="date"
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                        className="w-40"
                      />
                    </div>
                    <div className="space-y-1.5">
                      <Label htmlFor="rep-to">To</Label>
                      <Input
                        id="rep-to"
                        type="date"
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        className="w-40"
                      />
                    </div>
                    <div className="space-y-1.5">
                      <Label htmlFor="rep-group">Group by</Label>
                      <select
                        id="rep-group"
                        value={groupBy}
                        onChange={(e) => setGroupBy(e.target.value)}
                        className="h-9 w-40 rounded-md border border-input bg-background px-3 text-sm"
                      >
                        {GROUP_BYS.map((g) => (
                          <option key={g.value} value={g.value}>
                            {g.label}
                          </option>
                        ))}
                      </select>
                    </div>
                  </div>

                  <Card>
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm">
                        <thead>
                          <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <th className="px-4 py-2 font-medium">
                              {GROUP_BYS.find((g) => g.value === groupBy)?.label}
                            </th>
                            <th className="px-4 py-2 text-right font-medium">Hours</th>
                            <th className="px-4 py-2 text-right font-medium">Billable hours</th>
                            <th className="px-4 py-2 text-right font-medium">Billable amount</th>
                            <th className="px-4 py-2 text-right font-medium">Cost</th>
                            <th className="px-4 py-2 text-right font-medium">Profit</th>
                          </tr>
                        </thead>
                        <tbody>
                          {summaryQuery.isLoading ? (
                            <tr>
                              <td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">
                                Loading…
                              </td>
                            </tr>
                          ) : !summary || summary.rows.length === 0 ? (
                            <tr>
                              <td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">
                                No tracked time in this period.
                              </td>
                            </tr>
                          ) : (
                            summary.rows.map((row) => (
                              <tr key={row.key} className="border-b last:border-0">
                                <td className="px-4 py-2.5">{row.label}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums">
                                  {row.hours.toFixed(2)}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums">
                                  {row.billableHours.toFixed(2)}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums">
                                  {amountsLabel(row.amounts)}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums">
                                  {amountsLabel(row.costs)}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums">
                                  {amountsLabel(row.profit)}
                                </td>
                              </tr>
                            ))
                          )}
                        </tbody>
                        {summary && summary.rows.length > 0 && (
                          <tfoot>
                            <tr className="bg-muted/40 font-medium">
                              <td className="px-4 py-2">Total</td>
                              <td className="px-4 py-2 text-right tabular-nums">
                                {summary.totals.hours.toFixed(2)}
                              </td>
                              <td className="px-4 py-2 text-right tabular-nums">
                                {summary.totals.billableHours.toFixed(2)}
                              </td>
                              <td className="px-4 py-2 text-right tabular-nums">
                                {amountsLabel(summary.totals.amounts)}
                              </td>
                              <td className="px-4 py-2 text-right tabular-nums">
                                {amountsLabel(summary.totals.costs)}
                              </td>
                              <td className="px-4 py-2 text-right tabular-nums">
                                {amountsLabel(summary.totals.profit)}
                              </td>
                            </tr>
                          </tfoot>
                        )}
                      </table>
                    </div>
                  </Card>
                </>
              )}

              {tab === "uninvoiced" && (
                <Card>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                          <th className="px-4 py-2 font-medium">Engagement</th>
                          <th className="px-4 py-2 font-medium">Client</th>
                          <th className="px-4 py-2 text-right font-medium">Entries</th>
                          <th className="px-4 py-2 text-right font-medium">Hours</th>
                          <th className="px-4 py-2 text-right font-medium">Amount</th>
                          <th className="px-4 py-2" />
                        </tr>
                      </thead>
                      <tbody>
                        {uninvoicedQuery.isLoading ? (
                          <tr>
                            <td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">
                              Loading…
                            </td>
                          </tr>
                        ) : uninvoiced.length === 0 ? (
                          <tr>
                            <td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">
                              Nothing to bill — every billable hour is invoiced.
                            </td>
                          </tr>
                        ) : (
                          uninvoiced.map((row) => (
                            <tr key={row.engagementId} className="border-b last:border-0">
                              <td className="px-4 py-2.5 font-medium">{row.engagement}</td>
                              <td className="px-4 py-2.5 text-muted-foreground">
                                {row.client ?? "—"}
                              </td>
                              <td className="px-4 py-2.5 text-right tabular-nums">
                                {row.entryCount}
                              </td>
                              <td className="px-4 py-2.5 text-right tabular-nums">
                                {row.hours.toFixed(2)}
                              </td>
                              <td className="px-4 py-2.5 text-right tabular-nums">
                                {formatMoney(row.amount, row.currency)}
                              </td>
                              <td className="px-4 py-2.5 text-right">
                                <Link
                                  href={`/invoices?generate=${encodeURIComponent(row.engagementId)}`}
                                  className="text-sm text-primary hover:underline"
                                >
                                  Create invoice
                                </Link>
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </div>
                </Card>
              )}
            </>
          )}
        </div>
      </div>
    </>
  );
};

export default ReportsPage;
