import { describe, expect, it } from "vitest";
import { cfvPath, parseViolations, violationFor, violationsFromBody } from "./violations";

describe("violationsFromBody", () => {
  it("maps a violations list to propertyPath -> message", () => {
    const map = violationsFromBody({
      violations: [
        { propertyPath: "title", message: "Too short" },
        { propertyPath: "email", message: "Invalid" },
      ],
    });
    expect(map).toEqual({ title: "Too short", email: "Invalid" });
  });

  it("keeps the first message for a repeated path", () => {
    const map = violationsFromBody({
      violations: [
        { propertyPath: "title", message: "first" },
        { propertyPath: "title", message: "second" },
      ],
    });
    expect(map.title).toBe("first");
  });

  it("reads the JSON-LD hydra:violations key too", () => {
    const map = violationsFromBody({
      "hydra:violations": [{ propertyPath: "title", message: "Bad" }],
    });
    expect(map.title).toBe("Bad");
  });

  it("ignores malformed entries and returns {} for non-objects", () => {
    expect(violationsFromBody(null)).toEqual({});
    expect(violationsFromBody("nope")).toEqual({});
    expect(violationsFromBody({ violations: "nope" })).toEqual({});
    expect(violationsFromBody({ violations: [{ propertyPath: 1, message: "x" }, null] })).toEqual({});
  });
});

describe("parseViolations", () => {
  it("parses a Response body", async () => {
    const res = new Response(JSON.stringify({ violations: [{ propertyPath: "x", message: "y" }] }));
    expect(await parseViolations(res)).toEqual({ x: "y" });
  });

  it("returns {} for a non-JSON body", async () => {
    const res = new Response("not json");
    expect(await parseViolations(res)).toEqual({});
  });
});

describe("cfvPath", () => {
  it("builds the custom-field-value path", () => {
    expect(cfvPath(0)).toBe("customFieldValues[0].value");
    expect(cfvPath(2, ".amount")).toBe("customFieldValues[2].value.amount");
  });
});

describe("violationFor", () => {
  const map = {
    "customFieldValues[0].value.amount": "Bad amount",
    title: "Required",
  };

  it("matches an exact path", () => {
    expect(violationFor(map, "title")).toBe("Required");
  });

  it("matches a sub-path by prefix", () => {
    expect(violationFor(map, "customFieldValues[0].value")).toBe("Bad amount");
  });

  it("returns null when nothing matches", () => {
    expect(violationFor(map, "description")).toBeNull();
  });
});
