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

  it("strips surrounding quotes from single-token tag: and in: values", () => {
    // NB: the query is whitespace-split before quotes are stripped, so a
    // quoted value only round-trips when it has no spaces.
    const p = parseQuery('tag:"launch" in:"Space"');
    expect(p.tags).toEqual(["launch"]);
    expect(p.space).toBe("Space");
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
