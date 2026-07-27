import { describe, expect, it } from "vitest";
import {
  allOnMatrix,
  DEFAULT_NOTIFICATION_MATRIX,
  NOTIFICATION_ROWS,
  PUSH_PERMISSION_COPY,
} from "./notificationPrefs";

describe("notification preference defaults", () => {
  it("has a default-matrix entry for every row", () => {
    for (const row of NOTIFICATION_ROWS) {
      expect(DEFAULT_NOTIFICATION_MATRIX[row.key]).toBeDefined();
    }
    expect(Object.keys(DEFAULT_NOTIFICATION_MATRIX).sort()).toEqual(
      NOTIFICATION_ROWS.map((r) => r.key).sort(),
    );
  });

  it("uses unique row keys", () => {
    const keys = NOTIFICATION_ROWS.map((r) => r.key);
    expect(new Set(keys).size).toBe(keys.length);
  });
});

describe("allOnMatrix", () => {
  it("turns every row on for both channels", () => {
    const matrix = allOnMatrix();
    expect(Object.keys(matrix).sort()).toEqual(
      NOTIFICATION_ROWS.map((r) => r.key).sort(),
    );
    for (const channel of Object.values(matrix)) {
      expect(channel).toEqual({ inApp: true, email: true });
    }
  });
});

describe("PUSH_PERMISSION_COPY", () => {
  it("covers every state the push helper can report", () => {
    for (const state of ["default", "granted", "denied", "unsupported"] as const) {
      expect(PUSH_PERMISSION_COPY[state]).toBeTruthy();
    }
  });

  it("never leaks the raw spec vocabulary to the user", () => {
    // "default" in particular reads as "nothing to do" when it means the
    // opposite, which is the whole reason this map exists.
    for (const copy of Object.values(PUSH_PERMISSION_COPY)) {
      expect(copy).not.toMatch(/\b(default|granted|denied)\b/);
    }
  });

  it("tells the user what to do when push is blocked", () => {
    expect(PUSH_PERMISSION_COPY.denied).toMatch(/site settings/i);
  });
});
