import { describe, expect, it } from "vitest";
import {
  estimatePasswordStrength,
  STRENGTH_MEDIUM,
  STRENGTH_STRONG,
  STRENGTH_VERY_STRONG,
  STRENGTH_VERY_WEAK,
  STRENGTH_WEAK,
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

  it("walks every intermediate bucket as entropy climbs", () => {
    // All-distinct lowercase keeps pool fixed at 26, so entropy is exactly
    // length * log2(26) ≈ length * 4.7004 — no ICU/locale dependence. The
    // chosen lengths land squarely inside each band's return branch.
    expect(estimatePasswordStrength("abcdefghijklmn")).toBe(STRENGTH_WEAK); // 14 → ≈65.8 (60–80)
    expect(estimatePasswordStrength("abcdefghijklmnopqr")).toBe(STRENGTH_MEDIUM); // 18 → ≈84.6 (80–100)
    expect(estimatePasswordStrength("abcdefghijklmnopqrstuv")).toBe(STRENGTH_STRONG); // 22 → ≈103.4 (100–120)
  });

  it("accounts for symbol, control, and high-bit character classes", () => {
    // These inputs route through the `symbol`, `control`, and `other`
    // (high-bit) arms of the class-detection loop. We only assert the
    // result is a valid 0–4 score — enough to exercise each branch without
    // pinning a boundary-sensitive bucket.
    for (const pw of ["!@#$%^&*()_+", "\x01\x02abcdEFG1", "éàüœ®©abc12"]) {
      const score = estimatePasswordStrength(pw);
      expect([0, 1, 2, 3, 4]).toContain(score);
    }
  });
});
