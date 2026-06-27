import { describe, expect, it } from "vitest";
import {
  AVATAR_PALETTE,
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
