import { describe, expect, it } from "vitest";
import { displayName, initialsFor } from "./userDisplay";

describe("displayName", () => {
  it("prefers a trimmed nickname", () => {
    expect(displayName({ nickname: "  Bob  ", givenName: "Robert", email: "r@x.io" })).toBe("Bob");
  });

  it("falls back to given + family name", () => {
    expect(displayName({ givenName: "Robo", familyName: "Parker" })).toBe("Robo Parker");
    expect(displayName({ givenName: "Robo" })).toBe("Robo");
    expect(displayName({ familyName: "Parker" })).toBe("Parker");
  });

  it("falls back to email, then Unknown", () => {
    expect(displayName({ email: "r@x.io" })).toBe("r@x.io");
    expect(displayName({})).toBe("Unknown");
    expect(displayName({ nickname: "   ", givenName: " ", email: "  " })).toBe("Unknown");
  });
});

describe("initialsFor", () => {
  it("uses the first nickname character, upper-cased", () => {
    expect(initialsFor({ nickname: "bob" })).toBe("B");
  });

  it("combines given + family initials", () => {
    expect(initialsFor({ givenName: "Robo", familyName: "Parker" })).toBe("RP");
    expect(initialsFor({ givenName: "robo" })).toBe("R");
  });

  it("falls back to the email initial, then '?'", () => {
    expect(initialsFor({ email: "zed@x.io" })).toBe("Z");
    expect(initialsFor({})).toBe("?");
    expect(initialsFor({ givenName: " ", email: "" })).toBe("?");
  });
});
