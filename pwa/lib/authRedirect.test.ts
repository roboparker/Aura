import { describe, expect, it } from "vitest";
import { isSafeNextPath, safeNextPath, signinHrefForCurrent } from "./authRedirect";

describe("isSafeNextPath", () => {
  it("accepts a plain root-relative path", () => {
    expect(isSafeNextPath("/boards")).toBe(true);
    expect(isSafeNextPath("/tasks?status=open#top")).toBe(true);
  });

  it("rejects non-strings and empty strings", () => {
    expect(isSafeNextPath(undefined)).toBe(false);
    expect(isSafeNextPath(null)).toBe(false);
    expect(isSafeNextPath(42)).toBe(false);
    expect(isSafeNextPath("")).toBe(false);
  });

  it("rejects values that don't start with a single slash", () => {
    expect(isSafeNextPath("boards")).toBe(false);
    expect(isSafeNextPath("https://evil.com")).toBe(false);
    expect(isSafeNextPath("javascript:alert(1)")).toBe(false);
    expect(isSafeNextPath("data:text/html,x")).toBe(false);
  });

  it("rejects protocol-relative and backslash-smuggled hosts", () => {
    expect(isSafeNextPath("//evil.com/x")).toBe(false);
    expect(isSafeNextPath("/\\evil.com/x")).toBe(false);
  });

  it("rejects unresolved dynamic-route patterns", () => {
    // These leak in when router.asPath is read before the router is ready;
    // handed to router.push they throw "missing query values".
    expect(isSafeNextPath("/boards/[id]")).toBe(false);
    expect(isSafeNextPath("/spaces/[id]?tab=files")).toBe(false);
  });
});

describe("safeNextPath", () => {
  it("returns the normalised path for a safe candidate", () => {
    expect(safeNextPath("/tasks?status=open")).toBe("/tasks?status=open");
    expect(safeNextPath("/a/b#frag")).toBe("/a/b#frag");
  });

  it("falls back to /boards for hostile or invalid input", () => {
    expect(safeNextPath("//evil.com")).toBe("/boards");
    expect(safeNextPath("https://evil.com/x")).toBe("/boards");
    expect(safeNextPath(undefined)).toBe("/boards");
    expect(safeNextPath("")).toBe("/boards");
    // A captured route pattern must fall back, not crash router.push (the
    // bug where sign-in appeared to do nothing).
    expect(safeNextPath("/boards/[id]")).toBe("/boards");
  });

  it("honours a custom fallback", () => {
    expect(safeNextPath(undefined, "/settings/profile")).toBe("/settings/profile");
    expect(safeNextPath("//evil.com", "/home")).toBe("/home");
  });
});

describe("signinHrefForCurrent", () => {
  it("preserves a safe current path as ?next=", () => {
    expect(signinHrefForCurrent("/boards/123")).toBe(
      "/signin?next=%2Fprojects%2F123",
    );
  });

  it("encodes query strings in the preserved path", () => {
    expect(signinHrefForCurrent("/tasks?status=open")).toBe(
      `/signin?next=${encodeURIComponent("/tasks?status=open")}`,
    );
  });

  it("returns plain /signin for unsafe paths", () => {
    expect(signinHrefForCurrent(undefined)).toBe("/signin");
    expect(signinHrefForCurrent("//evil.com")).toBe("/signin");
  });

  it("does not loop back onto the auth pages themselves", () => {
    expect(signinHrefForCurrent("/signin")).toBe("/signin");
    expect(signinHrefForCurrent("/signin?next=/x")).toBe("/signin");
    expect(signinHrefForCurrent("/signup")).toBe("/signin");
  });
});
