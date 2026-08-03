import { describe, expect, it } from "vitest";
import {
  COMPARISON_GROUPS,
  PLAN_ENTITLEMENTS,
  PLAN_FEATURES,
  PLAN_KEYS,
  PLAN_LIMITS,
  PLAN_TIERS,
  formatUsd,
  priceFor,
  type PlanTier,
} from "./planTiers";

const tier = (key: string): PlanTier => {
  const found = PLAN_TIERS.find((t) => t.key === key);
  if (!found) throw new Error(`no tier ${key}`);
  return found;
};

const allRows = COMPARISON_GROUPS.flatMap((group) => group.rows);

describe("the comparison table", () => {
  it("covers every feature in the catalog", () => {
    // The guard that matters: adding a flag to PlanCatalog and mirroring it
    // into PLAN_ENTITLEMENTS without giving it a row would quietly ship a
    // pricing page that undersells the plan.
    const keys = new Set(allRows.map((row) => row.key));
    for (const feature of PLAN_FEATURES) {
      expect(keys.has(feature), `no table row for feature "${feature}"`).toBe(true);
    }
  });

  it("covers every limit in the catalog", () => {
    const keys = new Set(allRows.map((row) => row.key));
    for (const limit of PLAN_LIMITS) {
      expect(keys.has(limit), `no table row for limit "${limit}"`).toBe(true);
    }
  });

  it("gives every row a value for every plan", () => {
    for (const row of allRows) {
      for (const plan of PLAN_KEYS) {
        expect(row.values[plan], `${row.key} has no value for ${plan}`).toBeDefined();
      }
    }
  });

  it("lists each row once, so nothing is advertised twice", () => {
    const keys = allRows.map((row) => row.key);
    expect(new Set(keys).size).toBe(keys.length);
  });

  it("renders an unlimited limit as a word and a capped one as a number", () => {
    const members = allRows.find((row) => row.key === "space_members");
    expect(members?.values.free).toBe("Up to 5");
    expect(members?.values.business).toBe("Unlimited");
  });

  it("renders a zero allowance as not-included rather than as '0'", () => {
    // AI credits are 0 on Free/Pro. "0 / month" reads as a broken value; the
    // dash is the honest rendering of an allowance you don't have.
    const credits = allRows.find((row) => row.key === "ai_credits_per_month");
    expect(credits?.values.free).toBe(false);
    expect(credits?.values.business).toBe("2,000 / month");
  });
});

describe("PLAN_ENTITLEMENTS", () => {
  it("has an entry for every plan, feature and limit", () => {
    for (const plan of PLAN_KEYS) {
      const entitlements = PLAN_ENTITLEMENTS[plan];
      expect(Object.keys(entitlements.features).sort()).toEqual(
        [...PLAN_FEATURES].sort(),
      );
      expect(Object.keys(entitlements.limits).sort()).toEqual([...PLAN_LIMITS].sort());
    }
  });

  it("never removes a feature as the plan gets more expensive", () => {
    // Every tier's card promises "Everything in <previous>". This is that
    // claim, asserted.
    for (let i = 1; i < PLAN_KEYS.length; i += 1) {
      const lower = PLAN_ENTITLEMENTS[PLAN_KEYS[i - 1]];
      const higher = PLAN_ENTITLEMENTS[PLAN_KEYS[i]];
      for (const feature of PLAN_FEATURES) {
        if (lower.features[feature]) {
          expect(
            higher.features[feature],
            `${PLAN_KEYS[i]} drops "${feature}" granted by ${PLAN_KEYS[i - 1]}`,
          ).toBe(true);
        }
      }
    }
  });

  it("never lowers a limit as the plan gets more expensive", () => {
    for (let i = 1; i < PLAN_KEYS.length; i += 1) {
      const lower = PLAN_ENTITLEMENTS[PLAN_KEYS[i - 1]];
      const higher = PLAN_ENTITLEMENTS[PLAN_KEYS[i]];
      for (const limit of PLAN_LIMITS) {
        const below = lower.limits[limit];
        const above = higher.limits[limit];
        if (below === null) {
          // Unlimited below must stay unlimited above.
          expect(above, `${PLAN_KEYS[i]} caps "${limit}"`).toBeNull();
        } else if (above !== null) {
          expect(above, `${PLAN_KEYS[i]} lowers "${limit}"`).toBeGreaterThanOrEqual(below);
        }
      }
    }
  });
});

describe("priceFor", () => {
  it("shows the free plan as $0 forever, with no billing period", () => {
    expect(priceFor(tier("free"), false)).toEqual({
      amount: "$0",
      unit: "",
      period: "forever",
    });
    expect(priceFor(tier("free"), true).period).toBe("forever");
  });

  it("bills annual at ten months, so 'two months free' is true", () => {
    expect(priceFor(tier("pro"), false).amount).toBe("$10");
    expect(priceFor(tier("pro"), true)).toEqual({
      amount: "$100",
      unit: "",
      period: "per year",
    });
  });

  it("marks a per-seat plan's price as per seat", () => {
    expect(priceFor(tier("business"), false)).toEqual({
      amount: "$15",
      unit: "/seat",
      period: "per month",
    });
  });

  it("shows a sales-led plan as Custom with no period", () => {
    expect(priceFor(tier("enterprise"), false)).toEqual({
      amount: "Custom",
      unit: "",
      period: "",
    });
  });
});

describe("formatUsd", () => {
  it("drops the decimals on a whole-dollar price", () => {
    expect(formatUsd(1500)).toBe("$15");
  });

  it("keeps cents when the price isn't a whole dollar", () => {
    expect(formatUsd(1550)).toBe("$15.50");
  });
});
