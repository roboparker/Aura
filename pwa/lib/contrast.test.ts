import { describe, expect, it } from "vitest";

import { AVATAR_PALETTE } from "./avatarPalette";
import { contrastRatio, meetsAA, parseHex, readableForeground, relativeLuminance } from "./contrast";

describe("parseHex", () => {
  it("parses 6-digit hex with and without the hash", () => {
    expect(parseHex("#ff8800")).toEqual({ r: 255, g: 136, b: 0 });
    expect(parseHex("ff8800")).toEqual({ r: 255, g: 136, b: 0 });
  });

  it("expands 3-digit shorthand", () => {
    expect(parseHex("#f80")).toEqual({ r: 255, g: 136, b: 0 });
  });

  it("returns null for junk", () => {
    for (const bad of ["", "#", "#12345", "rgb(1,2,3)", "#gggggg", "nonsense"]) {
      expect(parseHex(bad)).toBeNull();
    }
  });
});

describe("relativeLuminance / contrastRatio", () => {
  it("anchors on the spec's endpoints", () => {
    expect(relativeLuminance("#000000")).toBeCloseTo(0, 5);
    expect(relativeLuminance("#ffffff")).toBeCloseTo(1, 5);
    expect(contrastRatio("#ffffff", "#000000")).toBeCloseTo(21, 2);
  });

  it("is symmetric and self-referentially 1", () => {
    expect(contrastRatio("#0d9488", "#ffffff")).toBeCloseTo(contrastRatio("#ffffff", "#0d9488"), 10);
    expect(contrastRatio("#4d7c0f", "#4d7c0f")).toBeCloseTo(1, 10);
  });

  it("reproduces the ratios measured in the audit", () => {
    // amber-500 and teal-600 against white — the two failing seeded tags.
    expect(contrastRatio("#ffffff", "#f59e0b")).toBeCloseTo(2.15, 1);
    expect(contrastRatio("#ffffff", "#0d9488")).toBeCloseTo(3.74, 1);
  });
});

describe("readableForeground", () => {
  it("picks dark text on light backgrounds", () => {
    expect(readableForeground("#f59e0b")).toBe("#111317"); // amber-500
    expect(readableForeground("#ffffff")).toBe("#111317");
  });

  it("picks white on dark backgrounds", () => {
    expect(readableForeground("#334155")).toBe("#ffffff"); // slate-700
    expect(readableForeground("#000000")).toBe("#ffffff");
  });

  it("falls back to white when the colour is unparseable", () => {
    expect(readableForeground("not-a-colour")).toBe("#ffffff");
  });

  it("lifts every previously-failing seeded tag colour to AA", () => {
    // These are the real values in the seeded database. Each failed at
    // 2.15 / 3.74 against the old hardcoded white.
    for (const bad of ["#f59e0b", "#0d9488", "#dc2626", "#7c3aed"]) {
      expect(meetsAA(bad)).toBe(true);
    }
  });
});

describe("AVATAR_PALETTE", () => {
  it("is entirely AA-legible under the chosen foreground", () => {
    for (const color of AVATAR_PALETTE) {
      expect(meetsAA(color), `${color} should reach AA`).toBe(true);
    }
  });

  it("still resolves to white, as its comment claims", () => {
    // The palette documents itself as verified against *white* text — guard
    // that, so a future palette edit can't silently flip entries to dark.
    for (const color of AVATAR_PALETTE) {
      expect(readableForeground(color), `${color} should keep white text`).toBe("#ffffff");
    }
  });
});
