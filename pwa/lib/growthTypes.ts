/**
 * Wire types for the growth funnel (#763). Mirrors `App\Growth\GrowthMetrics`.
 */

export interface GrowthPoint {
  period: string;
  value: number;
}

export const FUNNEL_STAGES = [
  "signups",
  "activations",
  "upgrades",
  "churn",
] as const;

export type FunnelStage = (typeof FUNNEL_STAGES)[number];

export interface GrowthSnapshot {
  users: number;
  waitlisted: number;
  activated: number;
  paidSubscriptions: number;
  paidSeats: number;
  mrrCents: number;
}

export interface GrowthResponse {
  from: string;
  to: string;
  interval: "day" | "week" | "month";
  funnel: Record<FunnelStage, GrowthPoint[]>;
  snapshot: GrowthSnapshot;
  notes: Record<string, string>;
}

export const STAGE_LABEL: Record<FunnelStage, string> = {
  signups: "Signups",
  activations: "Activated",
  upgrades: "Upgraded to paid",
  churn: "Churned",
};

export const STAGE_HINT: Record<FunnelStage, string> = {
  signups: "New accounts created",
  activations: "Created their first task",
  upgrades: "Started a paying subscription",
  churn: "Subscriptions canceled or unpaid",
};

/** Total across a stage's buckets. */
export const stageTotal = (points: GrowthPoint[] | undefined): number =>
  (points ?? []).reduce((sum, point) => sum + point.value, 0);

/**
 * Conversion between two stages, as a percentage of the earlier one.
 *
 * Null rather than zero when the denominator is empty — "0% converted" from no
 * signups at all is a claim the data doesn't support, and on a launch dashboard
 * that difference matters.
 */
export const conversionRate = (
  from: GrowthPoint[] | undefined,
  to: GrowthPoint[] | undefined,
): number | null => {
  const denominator = stageTotal(from);
  if (denominator === 0) return null;
  return (stageTotal(to) / denominator) * 100;
};

/** "$1,234.56" from minor units. */
export const formatMoneyCents = (cents: number): string =>
  new Intl.NumberFormat(undefined, {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(cents / 100);

/**
 * Merges every funnel stage onto one shared period axis so a single chart can
 * plot them together.
 *
 * A stage with no rows in a period is filled with 0 here rather than left
 * undefined — unlike the business metrics, a period genuinely did have zero
 * signups if none are recorded, and a gap would read as missing data.
 */
export const mergeFunnel = (
  funnel: Partial<Record<FunnelStage, GrowthPoint[]>>,
): Array<Record<string, string | number>> => {
  const periods = new Set<string>();
  for (const stage of FUNNEL_STAGES) {
    for (const point of funnel[stage] ?? []) periods.add(point.period);
  }

  return [...periods]
    .sort((a, b) => a.localeCompare(b))
    .map((period) => {
      const row: Record<string, string | number> = { period };
      for (const stage of FUNNEL_STAGES) {
        row[stage] =
          (funnel[stage] ?? []).find((p) => p.period === period)?.value ?? 0;
      }
      return row;
    });
};
