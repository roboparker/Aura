import { describe, expect, it } from "vitest";
import { hasTokens, parseQuery } from "./searchQuery";

describe("parseQuery", () => {
  it("separates free text from recognised tokens", () => {
    const p = parseQuery("ship tag:launch status:open @lin in:private the rest");
    expect(p.tags).toEqual(["launch"]);
    expect(p.status).toBe("open");
    expect(p.assignee).toBe("lin");
    expect(p.space).toBe("private");
    expect(p.text).toBe("ship the rest");
  });

  it("keeps quoted phrases (with spaces) together on tag: and in: values", () => {
    const p = parseQuery('tag:"launch blocker" in:"My Space"');
    expect(p.tags).toEqual(["launch blocker"]);
    expect(p.space).toBe("My Space");
  });

  it("still strips quotes from a space-free quoted value", () => {
    const p = parseQuery('tag:"launch"');
    expect(p.tags).toEqual(["launch"]);
  });

  it("mixes a quoted tag with trailing free text correctly", () => {
    const p = parseQuery('ship tag:"launch blocker" now');
    expect(p.tags).toEqual(["launch blocker"]);
    expect(p.text).toBe("ship now");
  });

  it("only accepts valid status values", () => {
    expect(parseQuery("status:open").status).toBe("open");
    expect(parseQuery("status:completed").status).toBe("completed");
    expect(parseQuery("status:bogus").status).toBe("");
    // An invalid status token falls through to nothing, not free text.
    expect(parseQuery("status:bogus").text).toBe("");
  });

  it("treats unknown tokens as free text", () => {
    const p = parseQuery("has:video hello");
    expect(p.text).toBe("has:video hello");
    expect(hasTokens("has:video hello")).toBe(false);
  });

  it("ignores a bare @ with no handle", () => {
    expect(parseQuery("@").assignee).toBeNull();
    expect(parseQuery("@").text).toBe("@");
  });

  it("collects multiple tags", () => {
    expect(parseQuery("tag:a tag:b").tags).toEqual(["a", "b"]);
  });
});

describe("hasTokens", () => {
  it("is true when any recognised token is present", () => {
    expect(hasTokens("tag:x")).toBe(true);
    expect(hasTokens("@me")).toBe(true);
    expect(hasTokens("just words")).toBe(false);
    expect(hasTokens("")).toBe(false);
  });
});
