import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * Behavioral tests for the global session-expiry detector. The module
 * keeps process-wide state (installed once, armed flag, one-shot guard),
 * so each case loads a fresh copy via `vi.resetModules()` + dynamic import
 * and stands up a minimal fake `window` the interceptor can wrap.
 */

const ORIGIN = "https://app.test";

type FetchStub = ReturnType<typeof vi.fn>;

function installFakeWindow(status: number): FetchStub {
  const fetchStub = vi.fn(async () => ({ status }) as unknown as Response);
  // The detector reads window.location + window.fetch and reassigns the
  // latter with its wrapper.
  (globalThis as { window?: unknown }).window = {
    location: { origin: ORIGIN, href: `${ORIGIN}/now` },
    fetch: fetchStub,
  };
  return fetchStub;
}

async function loadModule() {
  vi.resetModules();
  return import("./sessionExpiry");
}

afterEach(() => {
  delete (globalThis as { window?: unknown }).window;
  vi.restoreAllMocks();
});

describe("session expiry detector", () => {
  beforeEach(() => {
    installFakeWindow(401);
  });

  it("ignores a 401 when no session is armed", async () => {
    const mod = await loadModule();
    const handler = vi.fn();
    mod.setSessionExpiredHandler(handler);
    mod.installSessionExpiryInterceptor();

    await window.fetch(`${ORIGIN}/api/me`);

    expect(handler).not.toHaveBeenCalled();
  });

  it("fires once when an armed session 401s on a protected endpoint", async () => {
    const mod = await loadModule();
    const handler = vi.fn();
    mod.setSessionExpiredHandler(handler);
    mod.installSessionExpiryInterceptor();
    mod.armSessionExpiry(true);

    await window.fetch(`${ORIGIN}/tasks`);
    await window.fetch(`${ORIGIN}/projects`);

    expect(handler).toHaveBeenCalledTimes(1);
  });

  it("never fires on auth endpoints even when armed", async () => {
    const mod = await loadModule();
    const handler = vi.fn();
    mod.setSessionExpiredHandler(handler);
    mod.installSessionExpiryInterceptor();
    mod.armSessionExpiry(true);

    await window.fetch(`${ORIGIN}/auth/login`);
    await window.fetch(`${ORIGIN}/auth/2fa-check`);
    await window.fetch(`${ORIGIN}/auth/change-password`);

    expect(handler).not.toHaveBeenCalled();
  });

  it("re-arms after a disarm so a later session can expire again", async () => {
    const mod = await loadModule();
    const handler = vi.fn();
    mod.setSessionExpiredHandler(handler);
    mod.installSessionExpiryInterceptor();

    mod.armSessionExpiry(true);
    await window.fetch(`${ORIGIN}/tasks`);
    expect(handler).toHaveBeenCalledTimes(1);

    // Logout (disarm re-primes the one-shot guard), then a new session.
    mod.armSessionExpiry(false);
    mod.armSessionExpiry(true);
    await window.fetch(`${ORIGIN}/tasks`);

    expect(handler).toHaveBeenCalledTimes(2);
  });

  it("passes a non-401 response straight through untouched", async () => {
    installFakeWindow(200);
    const mod = await loadModule();
    const handler = vi.fn();
    mod.setSessionExpiredHandler(handler);
    mod.installSessionExpiryInterceptor();
    mod.armSessionExpiry(true);

    const res = await window.fetch(`${ORIGIN}/tasks`);

    expect(res.status).toBe(200);
    expect(handler).not.toHaveBeenCalled();
  });
});
