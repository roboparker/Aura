import { describe, expect, it } from "vitest";
import {
  estimatePasswordStrength,
  STRENGTH_VERY_STRONG,
  STRENGTH_VERY_WEAK,
} from "./passwordStrength";

describe("estimatePasswordStrength", () => {
  it("scores an empty password as very weak", () => {
    expect(estimatePasswordStrength("")).toBe(STRENGTH_VERY_WEAK);
  });

  it("scores a short single-class password as very weak", () => {
    // 8 identical lowercase chars: chars=1, pool=26 → entropy ≈ 4.7.
    expect(estimatePasswordStrength("aaaaaaaa")).toBe(STRENGTH_VERY_WEAK);
  });

  it("scores a long all-distinct multi-class password as very strong", () => {
    // 20 distinct chars across lower/upper/digit/symbol: pool=95,
    // entropy = 20 * log2(95) ≈ 131 → very strong.
    expect(estimatePasswordStrength("9aB#cD2!eF5@gH8?iJ1%")).toBe(STRENGTH_VERY_STRONG);
  });

  it("is monotonic as character diversity grows", () => {
    const weak = estimatePasswordStrength("password");
    const stronger = estimatePasswordStrength("P@ssw0rd!2024xyz");
    expect(stronger).toBeGreaterThan(weak);
  });
});
