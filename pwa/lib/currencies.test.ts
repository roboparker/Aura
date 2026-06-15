import { describe, expect, it } from "vitest";
import { CURRENCIES, currencyFractionDigits, findCurrency } from "./currencies";

describe("findCurrency", () => {
  it("looks up a known code case-insensitively", () => {
    expect(findCurrency("USD")?.name).toBe("US Dollar");
    expect(findCurrency("usd")?.code).toBe("USD");
  });

  it("returns undefined for an unknown code", () => {
    expect(findCurrency("ZZZ")).toBeUndefined();
  });
});

describe("currencyFractionDigits", () => {
  it("returns the curated digits for known codes", () => {
    expect(currencyFractionDigits("USD")).toBe(2);
    expect(currencyFractionDigits("JPY")).toBe(0);
    expect(currencyFractionDigits("KWD")).toBe(3);
    expect(currencyFractionDigits("jpy")).toBe(0); // case-insensitive
  });

  it("falls back to Intl for a valid code outside the curated list", () => {
    // TND (Tunisian Dinar) is a real 3-digit currency we don't curate.
    expect(currencyFractionDigits("TND")).toBe(3);
  });

  it("falls back to 2 for an ill-formed code that Intl rejects", () => {
    // A 2-letter code is not a well-formed ISO-4217 currency → Intl throws.
    expect(currencyFractionDigits("US")).toBe(2);
  });
});

describe("CURRENCIES list integrity", () => {
  it("has unique, upper-case 3-letter codes", () => {
    const codes = CURRENCIES.map((c) => c.code);
    expect(new Set(codes).size).toBe(codes.length);
    for (const code of codes) {
      expect(code).toMatch(/^[A-Z]{3}$/);
    }
  });

  it("only carries non-negative fraction digits", () => {
    for (const c of CURRENCIES) {
      expect(c.fractionDigits).toBeGreaterThanOrEqual(0);
    }
  });
});
