import dynamic from "next/dynamic";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ArrowDownRight, ArrowUpRight, LineChart, Minus } from "lucide-react";
import { apiGet } from "@/lib/apiClient";
import {
  ANALYTICS_INTERVALS,
  INTERVAL_LABEL,
  formatMetricValue,
  metricTotal,
  metricTrend,
  type AnalyticsInterval,
  type AnalyticsMetric,
  type AnalyticsResponse,
} from "@/lib/analyticsTypes";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";

// Recharts is heavy and only this tab needs it.
const MetricChart = dynamic(() => import("./MetricChart"), {
  ssr: false,
  loading: () => <Skeleton className="h-[220px] rounded-md bg-muted/50" />,
});

/** Months back from today, as a date-input value. */
const monthsAgo = (months: number): string => {
  const now = new Date();
  const then = new Date(now.getFullYear(), now.getMonth() - months, 1);
  const pad = (n: number) => n.toString().padStart(2, "0");
  return `${then.getFullYear()}-${pad(then.getMonth() + 1)}-01`;
};

const today = (): string => {
  const now = new Date();
  const pad = (n: number) => n.toString().padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};

/**
 * The dominant currency of a money metric, used for the headline figure.
 * Non-money metrics have no currency at all.
 */
const headlineCurrency = (metric: AnalyticsMetric): string | null =>
  [...metric.series].sort((a, b) => b.points.length - a.points.length)[0]
    ?.currency ?? null;

const TrendPill = ({ trend }: { trend: number | null }) => {
  if (trend === null) {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
        <Minus className="h-3 w-3" /> No change to compare
      </span>
    );
  }

  const up = trend >= 0;
  const Icon = up ? ArrowUpRight : ArrowDownRight;
  return (
    <span
      className={`inline-flex items-center gap-1 text-xs font-medium ${
        up
          ? "text-emerald-600 dark:text-emerald-400"
          : "text-red-600 dark:text-red-400"
      }`}
    >
      <Icon className="h-3 w-3" />
      {Math.abs(trend).toFixed(0)}% over the period
    </span>
  );
};

/**
 * The analytics dashboard (#756) — one card per metric the caller is allowed to
 * see, each a headline total plus a trend chart over the selected window.
 *
 * Metrics the caller can't read never arrive in the response (the server omits
 * rather than errors), so this renders whatever it's given and doesn't do any
 * permission branching of its own. A member with time access but no invoicing
 * access simply sees a shorter dashboard.
 */
const AnalyticsDashboard = ({ spaceId }: { spaceId: string | null }) => {
  const [from, setFrom] = useState(() => monthsAgo(11));
  const [to, setTo] = useState(today);
  const [interval, setInterval] = useState<AnalyticsInterval>("month");

  const params = new URLSearchParams({ from, to, interval });
  const query = useQuery({
    queryKey: ["analytics", spaceId, from, to, interval],
    enabled: !!spaceId,
    queryFn: () =>
      apiGet<AnalyticsResponse>(`/spaces/${spaceId}/analytics?${params.toString()}`, {
        errorMessage: "Failed to load analytics.",
      }),
  });

  const metrics = query.data?.metrics ?? [];

  return (
    <>
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="an-from">From</Label>
          <Input
            id="an-from"
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="w-40"
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="an-to">To</Label>
          <Input
            id="an-to"
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="w-40"
          />
        </div>
        <div className="inline-flex rounded-md border p-0.5">
          {ANALYTICS_INTERVALS.map((value) => (
            <Button
              key={value}
              variant={interval === value ? "secondary" : "ghost"}
              size="sm"
              onClick={() => setInterval(value)}
            >
              {INTERVAL_LABEL[value]}
            </Button>
          ))}
        </div>
      </div>

      {query.isError && (
        <Alert variant="destructive" className="mb-4">
          <AlertDescription>
            Could not load analytics for this period.
          </AlertDescription>
        </Alert>
      )}

      {query.isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-[320px] rounded-xl border bg-muted/40" />
          ))}
        </div>
      ) : metrics.length === 0 ? (
        <Card className="px-6 py-16 text-center">
          <LineChart className="mx-auto mb-3 h-8 w-8 text-muted-foreground" />
          <p className="font-medium">No metrics available</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Analytics appear once there is tracked time or invoicing in this
            space — and cover only the areas your role can read.
          </p>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {metrics.map((metric) => {
            const total = metricTotal(metric);
            const empty = metric.series.length === 0;

            return (
              <Card key={metric.key} className="p-4" data-testid={`metric-${metric.key}`}>
                <div className="mb-3">
                  <p className="text-xs uppercase tracking-wide text-muted-foreground">
                    {metric.label}
                  </p>
                  <p className="mt-0.5 text-2xl font-semibold tabular-nums">
                    {formatMetricValue(total, metric.unit, headlineCurrency(metric))}
                  </p>
                  {!empty && <TrendPill trend={metricTrend(metric)} />}
                </div>

                {empty ? (
                  <div className="flex h-[220px] items-center justify-center rounded-md border border-dashed">
                    <p className="text-sm text-muted-foreground">
                      Nothing recorded in this period.
                    </p>
                  </div>
                ) : (
                  <MetricChart metric={metric} interval={interval} />
                )}
              </Card>
            );
          })}
        </div>
      )}
    </>
  );
};

export default AnalyticsDashboard;
