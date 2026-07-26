import { describe, expect, it } from "vitest";
import {
  conversionRate,
  formatMoneyCents,
  mergeFunnel,
  stageTotal,
  type GrowthPoint,
} from "./growthTypes";

const points = (...values: [string, number][]): GrowthPoint[] =>
  values.map(([period, value]) => ({ period, value }));

describe("stageTotal", () => {
  it("sums a stage's buckets", () => {
    expect(stageTotal(points(["2026-W01", 3], ["2026-W02", 4]))).toBe(7);
  });

  it("is zero for a missing stage", () => {
    expect(stageTotal(undefined)).toBe(0);
  });
});

describe("conversionRate", () => {
  it("expresses the later stage as a percentage of the earlier", () => {
    expect(conversionRate(points(["a", 200]), points(["a", 6]))).toBe(3);
  });

  it("is null when nobody entered the funnel, not zero", () => {
    // "0% converted" from no signups is a claim the data can't support.
    expect(conversionRate(points(["a", 0]), points(["a", 0]))).toBeNull();
    expect(conversionRate(undefined, points(["a", 5]))).toBeNull();
  });

  it("can exceed 100 when a later stage outruns the window", () => {
    // Someone who signed up before the window can still upgrade inside it.
    expect(conversionRate(points(["a", 2]), points(["a", 3]))).toBe(150);
  });
});

describe("formatMoneyCents", () => {
  it("renders minor units as currency", () => {
    expect(formatMoneyCents(5500)).toBe("$55.00");
    expect(formatMoneyCents(0)).toBe("$0.00");
  });
});

describe("mergeFunnel", () => {
  it("aligns every stage onto one sorted period axis", () => {
    const rows = mergeFunnel({
      signups: points(["2026-02", 5], ["2026-01", 10]),
      upgrades: points(["2026-01", 1]),
    });

    expect(rows).toEqual([
      { period: "2026-01", signups: 10, activations: 0, upgrades: 1, churn: 0 },
      { period: "2026-02", signups: 5, activations: 0, upgrades: 0, churn: 0 },
    ]);
  });

  it("fills a missing bucket with zero, not a gap", () => {
    // A period with no signups really did have none — unlike the per-space
    // business metrics, absence here is information, not missing data.
    const rows = mergeFunnel({ signups: points(["2026-01", 4]) });
    expect(rows[0].churn).toBe(0);
  });

  it("is empty for an empty funnel", () => {
    expect(mergeFunnel({})).toEqual([]);
  });
});
