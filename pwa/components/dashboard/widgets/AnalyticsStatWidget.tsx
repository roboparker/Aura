import { ArrowDownRight, ArrowUpRight } from "lucide-react";
import { formatMetricValue, type AnalyticsMetric } from "@/lib/analyticsTypes";
import type { WidgetProps } from "./registry";

interface StatPayload {
  metric: { key: string; label: string; unit: AnalyticsMetric["unit"] } | null;
  total: number;
  currency: string | null;
  change: number | null;
  months?: number;
}

/** One metric as a headline figure, with its change across the period. */
const AnalyticsStatWidget = ({ data, isLoading }: WidgetProps) => {
  if (isLoading) {
    return <div className="h-full animate-pulse rounded-md bg-muted/40" />;
  }

  const payload = (data ?? {}) as StatPayload;
  if (!payload.metric) {
    return (
      <p className="text-sm text-muted-foreground">
        This metric is no longer available.
      </p>
    );
  }

  const up = (payload.change ?? 0) >= 0;
  const Icon = up ? ArrowUpRight : ArrowDownRight;

  return (
    <div className="flex h-full flex-col justify-center">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">
        {payload.metric.label}
      </p>
      <p className="mt-1 text-2xl font-semibold tabular-nums">
        {formatMetricValue(payload.total, payload.metric.unit, payload.currency)}
      </p>
      {payload.change !== null && (
        <span
          className={`mt-0.5 inline-flex items-center gap-1 text-xs font-medium ${
            up
              ? "text-emerald-600 dark:text-emerald-400"
              : "text-red-600 dark:text-red-400"
          }`}
        >
          <Icon className="h-3 w-3" />
          {Math.abs(payload.change).toFixed(0)}%
          {payload.months ? ` over ${payload.months} months` : ""}
        </span>
      )}
    </div>
  );
};

export default AnalyticsStatWidget;
