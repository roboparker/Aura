import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { formatRelative, isRecent, timeGroup } from "./relativeTime";

// Pin "now" so Date.now()-relative output is deterministic.
const NOW = new Date("2026-06-14T12:00:00.000Z");
const minutes = (n: number) => n * 60_000;
const hours = (n: number) => n * 3_600_000;
const days = (n: number) => n * 86_400_000;

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(NOW);
});

afterEach(() => {
  vi.useRealTimers();
});

describe("isRecent", () => {
  it("is true within the window and false outside it", () => {
    expect(isRecent(new Date(NOW.getTime() - minutes(1)), minutes(5))).toBe(true);
    expect(isRecent(new Date(NOW.getTime() - minutes(10)), minutes(5))).toBe(false);
  });

  it("accepts an ISO string as well as a Date", () => {
    expect(isRecent(new Date(NOW.getTime() - minutes(1)).toISOString(), minutes(5))).toBe(true);
  });

  it("is false for an unparseable timestamp", () => {
    expect(isRecent("not-a-date", minutes(5))).toBe(false);
  });
});

describe("formatRelative", () => {
  it("returns empty string for an invalid date", () => {
    expect(formatRelative("not-a-date")).toBe("");
  });

  it("buckets past timestamps into the right unit", () => {
    expect(formatRelative(new Date(NOW.getTime() - 30_000))).toMatch(/second|now/i);
    expect(formatRelative(new Date(NOW.getTime() - minutes(5)))).toMatch(/minute/);
    expect(formatRelative(new Date(NOW.getTime() - hours(3)))).toMatch(/hour/);
    expect(formatRelative(new Date(NOW.getTime() - days(2)))).toMatch(/day/);
    expect(formatRelative(new Date(NOW.getTime() - days(45)))).toMatch(/month/);
    expect(formatRelative(new Date(NOW.getTime() - days(400)))).toMatch(/year/);
  });

  it("handles future timestamps too", () => {
    expect(formatRelative(new Date(NOW.getTime() + minutes(5)))).toMatch(/minute/);
  });
});

describe("timeGroup", () => {
  it("buckets into Today / This week / Earlier", () => {
    expect(timeGroup(new Date(NOW.getTime() - hours(2)))).toBe("Today");
    expect(timeGroup(new Date(NOW.getTime() - days(3)))).toBe("This week");
    expect(timeGroup(new Date(NOW.getTime() - days(30)))).toBe("Earlier");
  });

  it("treats an invalid timestamp as Earlier", () => {
    expect(timeGroup("not-a-date")).toBe("Earlier");
  });
});
