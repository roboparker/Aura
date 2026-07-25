import { describe, expect, it } from "vitest";
import {
  formatAxisValue,
  formatMetricValue,
  formatPeriodLabel,
  mergeSeries,
  metricTotal,
  metricTrend,
  seriesKeys,
  type AnalyticsMetric,
} from "./analyticsTypes";

const money = (series: AnalyticsMetric["series"]): AnalyticsMetric => ({
  key: "invoiced",
  label: "Invoiced",
  unit: "money",
  series,
});

describe("formatMetricValue", () => {
  it("reads seconds as hours", () => {
    expect(formatMetricValue(7200, "seconds", null)).toBe("2.0 h");
    expect(formatMetricValue(5400, "seconds", null)).toBe("1.5 h");
  });

  it("reads money as minor units in its own currency", () => {
    expect(formatMetricValue(10_000, "money", "USD")).toBe("$100.00");
  });
});

describe("formatAxisValue", () => {
  it("abbreviates thousands so ticks stay narrow", () => {
    expect(formatAxisValue(1_250_000, "money")).toBe("12.5k");
    expect(formatAxisValue(45_000, "money")).toBe("450");
  });

  it("rounds seconds to whole hours", () => {
    expect(formatAxisValue(9000, "seconds")).toBe("3h");
  });
});

describe("formatPeriodLabel", () => {
  it("expands month buckets", () => {
    expect(formatPeriodLabel("2026-01", "month")).toBe("Jan 2026");
  });

  it("leaves day and week buckets alone", () => {
    expect(formatPeriodLabel("2026-01-15", "day")).toBe("2026-01-15");
    expect(formatPeriodLabel("2026-W03", "week")).toBe("2026-W03");
  });

  it("passes through a bucket it can't parse rather than showing undefined", () => {
    expect(formatPeriodLabel("2026-13", "month")).toBe("2026-13");
  });
});

describe("metricTotal", () => {
  it("sums across every series and point", () => {
    const metric = money([
      { currency: "USD", points: [{ period: "2026-01", value: 100 }] },
      { currency: "EUR", points: [{ period: "2026-01", value: 50 }] },
    ]);
    expect(metricTotal(metric)).toBe(150);
  });

  it("is zero for an empty metric", () => {
    expect(metricTotal(money([]))).toBe(0);
  });
});

describe("metricTrend", () => {
  it("reports growth between the first and last bucket", () => {
    const metric = money([
      {
        currency: "USD",
        points: [
          { period: "2026-01", value: 100 },
          { period: "2026-02", value: 150 },
        ],
      },
    ]);
    expect(metricTrend(metric)).toBe(50);
  });

  it("declines are negative", () => {
    const metric = money([
      {
        currency: "USD",
        points: [
          { period: "2026-01", value: 200 },
          { period: "2026-02", value: 100 },
        ],
      },
    ]);
    expect(metricTrend(metric)).toBe(-50);
  });

  it("has no trend from a single bucket", () => {
    const metric = money([
      { currency: "USD", points: [{ period: "2026-01", value: 100 }] },
    ]);
    expect(metricTrend(metric)).toBeNull();
  });

  it("refuses to divide by a zero baseline", () => {
    const metric = money([
      {
        currency: "USD",
        points: [
          { period: "2026-01", value: 0 },
          { period: "2026-02", value: 500 },
        ],
      },
    ]);
    expect(metricTrend(metric)).toBeNull();
  });
});

describe("mergeSeries", () => {
  it("aligns currencies onto shared periods, sorted", () => {
    const metric = money([
      {
        currency: "USD",
        points: [
          { period: "2026-02", value: 20 },
          { period: "2026-01", value: 10 },
        ],
      },
      { currency: "EUR", points: [{ period: "2026-01", value: 5 }] },
    ]);

    expect(mergeSeries(metric)).toEqual([
      { period: "2026-01", USD: 10, EUR: 5 },
      { period: "2026-02", USD: 20 },
    ]);
  });

  it("leaves a missing currency undefined rather than zero", () => {
    const metric = money([
      { currency: "USD", points: [{ period: "2026-01", value: 10 }] },
      { currency: "EUR", points: [{ period: "2026-02", value: 5 }] },
    ]);

    const rows = mergeSeries(metric);
    // A zero here would claim "earned nothing in EUR that month"; undefined
    // correctly says "no data".
    expect(rows[0].EUR).toBeUndefined();
    expect(rows[1].USD).toBeUndefined();
  });

  it("names a non-money series 'value'", () => {
    const metric: AnalyticsMetric = {
      key: "tracked_time",
      label: "Tracked time",
      unit: "seconds",
      series: [{ currency: null, points: [{ period: "2026-01", value: 3600 }] }],
    };
    expect(mergeSeries(metric)).toEqual([{ period: "2026-01", value: 3600 }]);
    expect(seriesKeys(metric)).toEqual(["value"]);
  });
});
