import { describe, expect, it } from "vitest";
import {
  AVATAR_PALETTE,
  PALETTE_NAMES,
  colorName,
  deterministicPaletteColor,
  resolveGroupColor,
  resolveSpaceColor,
} from "./avatarPalette";

describe("deterministicPaletteColor", () => {
  it("returns the first palette entry for an empty seed", () => {
    expect(deterministicPaletteColor("")).toBe(AVATAR_PALETTE[0]);
  });

  it("is stable for the same seed", () => {
    expect(deterministicPaletteColor("team-alpha")).toBe(
      deterministicPaletteColor("team-alpha"),
    );
  });

  it("always returns a value from the palette", () => {
    for (const seed of ["a", "longer-seed", "🙂", "Group 42"]) {
      expect(AVATAR_PALETTE).toContain(deterministicPaletteColor(seed));
    }
  });
});

describe("resolveSpaceColor", () => {
  it("prefers an explicit color", () => {
    expect(
      resolveSpaceColor({ color: "#123456", createdBy: { personalizedColor: "#abcdef" } }),
    ).toBe("#123456");
  });

  it("inherits the creator's personalized color", () => {
    expect(
      resolveSpaceColor({ color: null, createdBy: { personalizedColor: "#abcdef" } }),
    ).toBe("#abcdef");
  });

  it("falls back to the first palette entry", () => {
    expect(resolveSpaceColor({ color: null, createdBy: null })).toBe(AVATAR_PALETTE[0]);
    expect(resolveSpaceColor({ color: null, createdBy: {} })).toBe(AVATAR_PALETTE[0]);
  });
});

describe("resolveGroupColor", () => {
  it("prefers an explicit color, then the space color, then fallback", () => {
    expect(resolveGroupColor({ color: "#111111" })).toBe("#111111");
    expect(resolveGroupColor({ spaceSummary: { color: "#222222" } })).toBe("#222222");
    expect(resolveGroupColor({})).toBe(AVATAR_PALETTE[0]);
    expect(resolveGroupColor({ color: null, spaceSummary: { color: null } })).toBe(
      AVATAR_PALETTE[0],
    );
  });
});

describe("colorName", () => {
  // The drift guard: a colour added to the palette without a name would
  // silently go back to announcing itself as a hex code, which is the exact
  // defect this map exists to fix.
  it("names every palette entry", () => {
    for (const hex of AVATAR_PALETTE) {
      expect(PALETTE_NAMES[hex], `no name for ${hex}`).toBeTruthy();
      expect(colorName(hex)).not.toMatch(/^#/);
    }
  });

  it("gives each palette entry a distinct name", () => {
    const names = AVATAR_PALETTE.map(colorName);
    expect(new Set(names).size).toBe(names.length);
  });

  it("is case- and whitespace-insensitive", () => {
    expect(colorName("#0F766E")).toBe("Teal");
    expect(colorName("  #0f766e  ")).toBe("Teal");
  });

  it("falls back to the hex for an off-palette colour", () => {
    // No worse than the old behaviour, and the app offers nothing off-palette.
    expect(colorName("#123456")).toBe("#123456");
  });
});
