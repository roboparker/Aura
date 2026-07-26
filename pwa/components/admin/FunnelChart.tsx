import {
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import {
  FUNNEL_STAGES,
  STAGE_LABEL,
  mergeFunnel,
  type FunnelStage,
  type GrowthPoint,
} from "@/lib/growthTypes";

/** Okabe–Ito, same colour-blind-safe set the business metrics use. */
const STAGE_COLOR: Record<FunnelStage, string> = {
  signups: "#0072b2",
  activations: "#009e73",
  upgrades: "#e69f00",
  churn: "#d55e00",
};

interface Props {
  funnel: Partial<Record<FunnelStage, GrowthPoint[]>>;
}

/**
 * All four funnel stages on one axis.
 *
 * Lines rather than areas: these are counts of distinct events that need
 * comparing against each other, and four stacked fills would obscure the very
 * comparison the chart exists for.
 */
const FunnelChart = ({ funnel }: Props) => {
  const rows = mergeFunnel(funnel);

  if (rows.length === 0) {
    return (
      <div className="flex h-[260px] items-center justify-center rounded-md border border-dashed">
        <p className="text-sm text-muted-foreground">
          No activity in this period.
        </p>
      </div>
    );
  }

  return (
    <ResponsiveContainer width="100%" height={260}>
      <LineChart data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
        <XAxis
          dataKey="period"
          tickLine={false}
          axisLine={false}
          tick={{ fontSize: 11 }}
          className="fill-muted-foreground"
          minTickGap={16}
        />
        <YAxis
          tickLine={false}
          axisLine={false}
          width={36}
          allowDecimals={false}
          tick={{ fontSize: 11 }}
          className="fill-muted-foreground"
        />
        <Tooltip
          formatter={(value, name) => [value, STAGE_LABEL[name as FunnelStage] ?? name]}
          contentStyle={{
            borderRadius: 8,
            border: "1px solid var(--border)",
            background: "var(--popover)",
            color: "var(--popover-foreground)",
            fontSize: 12,
          }}
        />
        <Legend
          wrapperStyle={{ fontSize: 12 }}
          formatter={(value) => STAGE_LABEL[value as FunnelStage] ?? value}
        />
        {FUNNEL_STAGES.map((stage) => (
          <Line
            key={stage}
            type="monotone"
            dataKey={stage}
            stroke={STAGE_COLOR[stage]}
            strokeWidth={2}
            dot={rows.length <= 2}
          />
        ))}
      </LineChart>
    </ResponsiveContainer>
  );
};

export default FunnelChart;
