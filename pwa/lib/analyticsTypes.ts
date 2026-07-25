import { formatMoney } from "@/lib/invoiceTypes";

/** One bucket of a series: the period label plus its aggregated value. */
export interface AnalyticsPoint {
  period: string;
  value: number;
}

/**
 * A metric can return several series — one per currency — because a space that
 * invoices in both USD and EUR can't meaningfully sum them into one number.
 * `currency` is null for non-money metrics.
 */
export interface AnalyticsSeries {
  currency: string | null;
  points: AnalyticsPoint[];
}

export interface AnalyticsMetric {
  key: string;
  label: string;
  unit: "money" | "seconds";
  series: AnalyticsSeries[];
}

export interface AnalyticsResponse {
  from: string;
  to: string;
  interval: AnalyticsInterval;
  metrics: AnalyticsMetric[];
}

export const ANALYTICS_INTERVALS = ["day", "week", "month"] as const;
export type AnalyticsInterval = (typeof ANALYTICS_INTERVALS)[number];

export const INTERVAL_LABEL: Record<AnalyticsInterval, string> = {
  day: "Daily",
  week: "Weekly",
  month: "Monthly",
};

/**
 * Formats a raw metric value for display.
 *
 * Money arrives in minor units (cents), seconds as an integer count — the two
 * need very different treatment, and getting it wrong shows a 100× error, so
 * the unit travels with the data rather than being inferred from the key.
 */
export const formatMetricValue = (
  value: number,
  unit: AnalyticsMetric["unit"],
  currency: string | null,
): string => {
  if (unit === "seconds") return `${(value / 3600).toFixed(1)} h`;
  return formatMoney(value, currency ?? "USD");
};

/**
 * A compact axis label — full precision belongs in the tooltip, not squeezed
 * into a tick.
 */
export const formatAxisValue = (
  value: number,
  unit: AnalyticsMetric["unit"],
): string => {
  if (unit === "seconds") return `${Math.round(value / 3600)}h`;
  const major = value / 100;
  if (Math.abs(major) >= 1000) return `${(major / 1000).toFixed(1)}k`;
  return major.toFixed(0);
};

/**
 * Turns a bucket key into something readable.
 *
 * The server emits `YYYY-MM-DD`, `IYYY-"W"IW`, or `YYYY-MM` depending on the
 * interval. Months are expanded to "Jan 2026"; the other two already read
 * clearly enough and are left alone.
 */
export const formatPeriodLabel = (
  period: string,
  interval: AnalyticsInterval,
): string => {
  if (interval !== "month") return period;
  const [year, month] = period.split("-");
  const index = Number(month) - 1;
  const names = [
    "Jan", "Feb", "Mar", "Apr", "May", "Jun",
    "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
  ];
  return names[index] ? `${names[index]} ${year}` : period;
};

/** Sum of every point across every series — the headline number on a card. */
export const metricTotal = (metric: AnalyticsMetric): number =>
  metric.series.reduce(
    (sum, series) =>
      sum + series.points.reduce((inner, point) => inner + point.value, 0),
    0,
  );

/**
 * Percentage change between the first and last buckets of a metric's largest
 * series, or null when there isn't enough data (or the baseline is zero, where
 * a percentage would be meaningless rather than infinite).
 */
export const metricTrend = (metric: AnalyticsMetric): number | null => {
  const series = [...metric.series].sort(
    (a, b) => b.points.length - a.points.length,
  )[0];
  if (!series || series.points.length < 2) return null;

  const first = series.points[0].value;
  const last = series.points[series.points.length - 1].value;
  if (first === 0) return null;

  return ((last - first) / Math.abs(first)) * 100;
};

/**
 * Merges a metric's per-currency series into rows keyed by period, so a chart
 * can plot several currencies against one shared x-axis.
 *
 * Buckets with no rows are absent from the response entirely (they're `GROUP
 * BY` output, not a dense calendar), so a currency missing from a period is
 * left `undefined` rather than zero — a gap in the line is honest about "no
 * data", where a zero would assert "we earned nothing".
 */
export const mergeSeries = (
  metric: AnalyticsMetric,
): Array<Record<string, string | number>> => {
  const byPeriod = new Map<string, Record<string, string | number>>();

  for (const series of metric.series) {
    const key = series.currency ?? "value";
    for (const point of series.points) {
      const row = byPeriod.get(point.period) ?? { period: point.period };
      row[key] = point.value;
      byPeriod.set(point.period, row);
    }
  }

  return [...byPeriod.values()].sort((a, b) =>
    String(a.period).localeCompare(String(b.period)),
  );
};

/** The data keys present in a merged row set, excluding the x-axis field. */
export const seriesKeys = (metric: AnalyticsMetric): string[] => [
  ...new Set(metric.series.map((s) => s.currency ?? "value")),
];
