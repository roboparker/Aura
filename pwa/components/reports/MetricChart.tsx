import {
  Area,
  AreaChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import {
  formatAxisValue,
  formatMetricValue,
  formatPeriodLabel,
  mergeSeries,
  seriesKeys,
  type AnalyticsInterval,
  type AnalyticsMetric,
} from "@/lib/analyticsTypes";

/**
 * Colour-blind-safe series colours (Okabe–Ito), which also hold up on both the
 * light and dark grounds. Most metrics draw a single series; the extras only
 * come into play for a multi-currency space.
 */
const SERIES_COLORS = ["#0072b2", "#e69f00", "#009e73", "#cc79a7", "#56b4e9"];

interface Props {
  metric: AnalyticsMetric;
  interval: AnalyticsInterval;
}

/**
 * One metric as a filled area chart.
 *
 * Imported dynamically by {@link AnalyticsDashboard} — Recharts pulls in D3
 * internals and has no business in the initial bundle of an app whose main job
 * is task lists.
 */
const MetricChart = ({ metric, interval }: Props) => {
  const rows = mergeSeries(metric).map((row) => ({
    ...row,
    label: formatPeriodLabel(String(row.period), interval),
  }));
  const keys = seriesKeys(metric);
  const multiCurrency = keys.length > 1;

  return (
    <ResponsiveContainer width="100%" height={220}>
      <AreaChart data={rows} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
        <defs>
          {keys.map((key, i) => (
            <linearGradient
              key={key}
              id={`fill-${metric.key}-${key}`}
              x1="0"
              y1="0"
              x2="0"
              y2="1"
            >
              <stop
                offset="0%"
                stopColor={SERIES_COLORS[i % SERIES_COLORS.length]}
                stopOpacity={0.28}
              />
              <stop
                offset="100%"
                stopColor={SERIES_COLORS[i % SERIES_COLORS.length]}
                stopOpacity={0.02}
              />
            </linearGradient>
          ))}
        </defs>

        <CartesianGrid
          strokeDasharray="3 3"
          vertical={false}
          className="stroke-border"
        />
        <XAxis
          dataKey="label"
          tickLine={false}
          axisLine={false}
          tick={{ fontSize: 11 }}
          className="fill-muted-foreground"
          minTickGap={16}
        />
        <YAxis
          tickLine={false}
          axisLine={false}
          width={44}
          tick={{ fontSize: 11 }}
          className="fill-muted-foreground"
          tickFormatter={(value: number) => formatAxisValue(value, metric.unit)}
        />
        <Tooltip
          formatter={(value, name) => [
            formatMetricValue(
              Number(value),
              metric.unit,
              name === "value" ? null : String(name),
            ),
            metric.label,
          ]}
          contentStyle={{
            borderRadius: 8,
            border: "1px solid var(--border)",
            background: "var(--popover)",
            color: "var(--popover-foreground)",
            fontSize: 12,
          }}
        />
        {multiCurrency && <Legend wrapperStyle={{ fontSize: 12 }} />}

        {keys.map((key, i) => (
          <Area
            key={key}
            type="monotone"
            dataKey={key}
            name={key}
            stroke={SERIES_COLORS[i % SERIES_COLORS.length]}
            strokeWidth={2}
            fill={`url(#fill-${metric.key}-${key})`}
            // Gaps stay gaps: a period with no rows isn't a zero.
            connectNulls={false}
            dot={rows.length <= 2}
          />
        ))}
      </AreaChart>
    </ResponsiveContainer>
  );
};

export default MetricChart;
