import { describe, expect, it } from "vitest";
import { PRODUCT_NAME, pageTitle } from "./pageTitle";

describe("pageTitle", () => {
  it("appends the product name after an em dash", () => {
    expect(pageTitle("Boards")).toBe("Boards — Madori");
  });

  it("joins multiple parts with a middle dot", () => {
    expect(pageTitle("Roles", "Launch team")).toBe("Roles · Launch team — Madori");
  });

  it("drops nullish and empty parts rather than emitting a stray separator", () => {
    // The common case: a title rendered while its subject is still loading.
    expect(pageTitle("Board", null)).toBe("Board — Madori");
    expect(pageTitle(undefined, "Board")).toBe("Board — Madori");
    expect(pageTitle("Board", "")).toBe("Board — Madori");
    expect(pageTitle("Board", "   ")).toBe("Board — Madori");
  });

  it("trims parts", () => {
    expect(pageTitle("  Boards  ")).toBe("Boards — Madori");
  });

  it("falls back to the bare product name when given nothing usable", () => {
    expect(pageTitle()).toBe(PRODUCT_NAME);
    expect(pageTitle(null, undefined, "")).toBe(PRODUCT_NAME);
  });

  it("never emits the old hyphen separator", () => {
    expect(pageTitle("Boards")).not.toContain(" - ");
  });
});
