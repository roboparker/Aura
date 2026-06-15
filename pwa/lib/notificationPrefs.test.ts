import { describe, expect, it } from "vitest";
import {
  allOnMatrix,
  DEFAULT_NOTIFICATION_MATRIX,
  NOTIFICATION_ROWS,
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
