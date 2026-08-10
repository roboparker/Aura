import { describe, expect, it } from "vitest";
import {
  creditUsagePercent,
  isAgentMember,
  type AiCreditBalance,
} from "./agentTypes";

const balance = (over: Partial<AiCreditBalance> = {}): AiCreditBalance => ({
  period: "2026-08",
  unlimited: false,
  usedCredits: 0,
  allowanceCredits: 2000,
  usedTokens: 0,
  allowanceTokens: 2_000_000,
  remainingTokens: 2_000_000,
  tokensPerCredit: 1000,
  organization: { id: "org-1", name: "Acme" },
  unavailableReason: null,
  unavailableMessage: null,
  ...over,
});

describe("isAgentMember", () => {
  it("only treats an explicit flag as an agent", () => {
    expect(isAgentMember({ isAgent: true })).toBe(true);
    expect(isAgentMember({ isAgent: false })).toBe(false);
  });

  it("treats a missing flag as a person", () => {
    // The safe direction for a roster: wrongly hiding a colleague is worse
    // than showing an agent, so a payload that doesn't serialize the field
    // must not make everyone disappear.
    expect(isAgentMember({})).toBe(false);
  });
});

describe("creditUsagePercent", () => {
  it("reports the share of the monthly allowance consumed", () => {
    expect(creditUsagePercent(balance({ usedCredits: 500 }))).toBe(25);
    expect(creditUsagePercent(balance({ usedCredits: 1600 }))).toBe(80);
  });

  it("clamps an overspend to a full bar rather than overflowing it", () => {
    expect(creditUsagePercent(balance({ usedCredits: 5000 }))).toBe(100);
  });

  it("shows nothing consumed when the plan is unlimited", () => {
    // A meter against an unbounded allowance is meaningless; the UI shows a
    // running total instead.
    expect(
      creditUsagePercent(
        balance({ unlimited: true, allowanceCredits: null, usedCredits: 900 }),
      ),
    ).toBe(0);
  });

  it("does not divide by a zero allowance", () => {
    // The Free and Pro default. Without the guard this is NaN, which renders
    // as a broken bar on the most common plan there is.
    expect(
      creditUsagePercent(balance({ allowanceCredits: 0, usedCredits: 0 })),
    ).toBe(0);
  });
});
