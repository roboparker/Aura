import { describe, expect, it } from "vitest";
import {
  collectionMembers,
  collectionTotal,
  metaFor,
  NOTIFICATION_META,
} from "./notificationTypes";

describe("collectionMembers", () => {
  it("reads the plain `member` key", () => {
    expect(collectionMembers({ member: [{ id: "1" } as never] })).toHaveLength(1);
  });

  it("falls back to the JSON-LD `hydra:member` key, then []", () => {
    expect(collectionMembers({ "hydra:member": [{ id: "1" } as never] })).toHaveLength(1);
    expect(collectionMembers({})).toEqual([]);
  });
});

describe("collectionTotal", () => {
  it("reads totalItems, then hydra:totalItems, then 0", () => {
    expect(collectionTotal({ totalItems: 5 })).toBe(5);
    expect(collectionTotal({ "hydra:totalItems": 7 })).toBe(7);
    expect(collectionTotal({})).toBe(0);
  });
});

describe("metaFor", () => {
  it("returns the matching meta for a known type", () => {
    expect(metaFor("mention")).toBe(NOTIFICATION_META.mention);
    expect(metaFor("status").label).toBe("STATUS");
  });

  it("returns the UPDATE fallback for an unknown type", () => {
    expect(metaFor("totally-unknown").label).toBe("UPDATE");
  });
});
