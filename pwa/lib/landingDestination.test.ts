import { describe, expect, it } from "vitest";
import {
  DEFAULT_LANDING,
  landingPathFor,
  readLanding,
} from "./landingDestination";

describe("readLanding", () => {
  it("returns the default when preferences are missing or malformed", () => {
    expect(readLanding(undefined)).toEqual(DEFAULT_LANDING);
    expect(readLanding(null)).toEqual(DEFAULT_LANDING);
    // @ts-expect-error — exercising the runtime guard against a bad shape.
    expect(readLanding({ landing: "nope" })).toEqual(DEFAULT_LANDING);
  });

  it("passes through a recognised page", () => {
    expect(readLanding({ landing: { page: "tasks", spaceId: null } })).toEqual({
      page: "tasks",
      spaceId: null,
    });
  });

  it("falls back to the default page for an unknown page value", () => {
    expect(
      // @ts-expect-error — stale/unknown page from an older client.
      readLanding({ landing: { page: "bogus", spaceId: null } }),
    ).toEqual(DEFAULT_LANDING);
  });

  it("keeps spaceId only for the 'space' page", () => {
    expect(readLanding({ landing: { page: "space", spaceId: "abc" } })).toEqual({
      page: "space",
      spaceId: "abc",
    });
    // A spaceId riding on a non-space page is normalised away.
    expect(
      readLanding({ landing: { page: "tasks", spaceId: "abc" } }),
    ).toEqual({ page: "tasks", spaceId: null });
  });

  it("defaults a missing spaceId on the space page to null", () => {
    expect(
      // @ts-expect-error — spaceId omitted entirely.
      readLanding({ landing: { page: "space" } }),
    ).toEqual({ page: "space", spaceId: null });
  });
});

describe("landingPathFor", () => {
  it("maps each page to its route", () => {
    expect(landingPathFor({ page: "tasks", spaceId: null })).toBe("/tasks");
    expect(landingPathFor({ page: "notifications", spaceId: null })).toBe(
      "/notifications",
    );
    expect(landingPathFor({ page: "spaces", spaceId: null })).toBe("/spaces");
    expect(landingPathFor({ page: "space", spaceId: "x" })).toBe("/boards");
  });

  it("falls back to the default route for an unrecognised page", () => {
    // @ts-expect-error — defends against a value outside the union.
    expect(landingPathFor({ page: "bogus", spaceId: null })).toBe("/boards");
  });
});
