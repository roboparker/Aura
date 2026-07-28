import dynamic from "next/dynamic";
import type { AnalyticsInterval, AnalyticsMetric } from "@/lib/analyticsTypes";
import type { WidgetProps } from "./registry";
import { Skeleton } from "@/components/ui/skeleton";

// Recharts is heavy; only a dashboard carrying a chart should pay for it.
const MetricChart = dynamic(() => import("@/components/reports/MetricChart"), {
  ssr: false,
  loading: () => <Skeleton className="h-full rounded-md bg-muted/50" />,
});

interface MetricPayload {
  metric: { key: string; label: string; unit: AnalyticsMetric["unit"] } | null;
  interval?: AnalyticsInterval;
  series?: AnalyticsMetric["series"];
}

/**
 * One analytics metric as a chart.
 *
 * Reuses `MetricChart` from the reports dashboard rather than drawing its own,
 * so a metric looks the same wherever it appears and improvements to one land
 * in both.
 */
const AnalyticsMetricWidget = ({ data, isLoading }: WidgetProps) => {
  if (isLoading) {
    return <Skeleton className="h-full rounded-md bg-muted/40" />;
  }

  const payload = (data ?? {}) as MetricPayload;

  // The configured metric no longer exists — the widget outlived it.
  if (!payload.metric) {
    return (
      <p className="text-sm text-muted-foreground">
        This metric is no longer available. Edit the widget to pick another.
      </p>
    );
  }

  const series = payload.series ?? [];
  if (series.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-sm text-muted-foreground">
          Nothing recorded in this period.
        </p>
      </div>
    );
  }

  return (
    <MetricChart
      metric={{
        key: payload.metric.key,
        label: payload.metric.label,
        unit: payload.metric.unit,
        series,
      }}
      interval={payload.interval ?? "month"}
    />
  );
};

export default AnalyticsMetricWidget;
