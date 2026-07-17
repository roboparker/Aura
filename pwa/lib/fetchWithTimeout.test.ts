import { afterEach, describe, expect, it, vi } from "vitest";
import { fetchWithTimeout } from "./fetchWithTimeout";

/**
 * The same-origin rebuild in fetchWithTimeout is the request-forgery guard
 * (#371): the user's session cookies must only ever reach our own origin.
 * These tests pin that a cross-origin (or origin-confusing) target is
 * refused, and that the value actually handed to fetch() is same-origin.
 */
describe("fetchWithTimeout same-origin guard", () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  const stubFetch = () => {
    const spy = vi.fn(async () => new Response("ok"));
    vi.stubGlobal("fetch", spy);
    return spy;
  };

  it("passes a plain same-origin path through to fetch", async () => {
    const spy = stubFetch();
    await fetchWithTimeout("/api/me");
    const target = spy.mock.calls[0][0] as string;
    expect(new URL(target).origin).toBe(window.location.origin);
    expect(new URL(target).pathname).toBe("/api/me");
  });

  it("refuses an absolute cross-origin URL", async () => {
    const spy = stubFetch();
    await expect(fetchWithTimeout("https://evil.example/steal")).rejects.toThrow(
      /cross-origin/i,
    );
    expect(spy).not.toHaveBeenCalled();
  });

  it("refuses a protocol-relative target that would flip the host", async () => {
    const spy = stubFetch();
    await expect(fetchWithTimeout("//evil.example/steal")).rejects.toThrow(
      /cross-origin/i,
    );
    expect(spy).not.toHaveBeenCalled();
  });

  it("never emits a cross-origin host even for an origin-confusing same-origin URL", async () => {
    const spy = stubFetch();
    // `<origin>//evil.example/x` is same-origin (host is the app), but a
    // naive `new URL(pathname, origin)` re-parse of "//evil.example/x"
    // would treat it as protocol-relative and flip the host. The
    // component-assignment rebuild must keep the trusted origin.
    await fetchWithTimeout(`${window.location.origin}//evil.example/x`);
    const target = spy.mock.calls[0][0] as string;
    expect(new URL(target).origin).toBe(window.location.origin);
    expect(new URL(target).host).not.toBe("evil.example");
  });

  it("rejects an unparseable URL", async () => {
    const spy = stubFetch();
    await expect(fetchWithTimeout("http://[::1")).rejects.toThrow(/invalid URL/i);
    expect(spy).not.toHaveBeenCalled();
  });
});
