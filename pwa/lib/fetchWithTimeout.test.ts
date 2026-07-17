import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fetchWithTimeout } from "./fetchWithTimeout";

/**
 * The same-origin rebuild in fetchWithTimeout is the request-forgery guard
 * (#371): the user's session cookies must only ever reach our own origin.
 * These tests pin that a cross-origin (or origin-confusing) target is
 * refused, and that the value actually handed to fetch() is same-origin.
 *
 * The unit env is `node` (no DOM), so — like sessionExpiry.test.ts — each
 * case stands up a minimal fake `window` for the guard to read.
 */
const ORIGIN = "https://app.test";

describe("fetchWithTimeout same-origin guard", () => {
  let spy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    spy = vi.fn(
      async (_input: RequestInfo | URL, _init?: RequestInit) =>
        ({ ok: true, status: 200 }) as unknown as Response,
    );
    (globalThis as { window?: unknown }).window = {
      location: { origin: ORIGIN, href: `${ORIGIN}/now` },
    };
    vi.stubGlobal("fetch", spy);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    delete (globalThis as { window?: unknown }).window;
  });

  /** The URL string actually handed to the stubbed fetch on its first call. */
  const firstTarget = (): string => String(spy.mock.calls[0]?.[0]);

  it("passes a plain same-origin path through to fetch", async () => {
    await fetchWithTimeout("/api/me");
    const target = firstTarget();
    expect(new URL(target).origin).toBe(ORIGIN);
    expect(new URL(target).pathname).toBe("/api/me");
  });

  it("refuses an absolute cross-origin URL", async () => {
    await expect(fetchWithTimeout("https://evil.example/steal")).rejects.toThrow(
      /cross-origin/i,
    );
    expect(spy).not.toHaveBeenCalled();
  });

  it("refuses a protocol-relative target that would flip the host", async () => {
    await expect(fetchWithTimeout("//evil.example/steal")).rejects.toThrow(
      /cross-origin/i,
    );
    expect(spy).not.toHaveBeenCalled();
  });

  it("never emits a cross-origin host even for an origin-confusing same-origin URL", async () => {
    // `<origin>//evil.example/x` is same-origin (host is the app), but a
    // naive `new URL(pathname, origin)` re-parse of "//evil.example/x"
    // would treat it as protocol-relative and flip the host. The
    // component-assignment rebuild must keep the trusted origin.
    await fetchWithTimeout(`${ORIGIN}//evil.example/x`);
    const target = firstTarget();
    expect(new URL(target).origin).toBe(ORIGIN);
    expect(new URL(target).host).not.toBe("evil.example");
  });

  it("rejects an unparseable URL", async () => {
    await expect(fetchWithTimeout("http://[::1")).rejects.toThrow(/invalid URL/i);
    expect(spy).not.toHaveBeenCalled();
  });
});
