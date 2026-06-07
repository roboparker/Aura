/**
 * Default deadline for auth + 2FA fetches in milliseconds. Long enough to
 * tolerate a normal slow round-trip; short enough that a wedged port
 * forward or stalled connection surfaces as a real error instead of a
 * spinner that sits forever (the failure mode that bit us on the
 * Reveal-codes button when Docker Desktop dropped its host↔WSL2 forward).
 */
const DEFAULT_TIMEOUT_MS = 10_000;

/**
 * Resolve the request target and refuse anything that isn't same-origin
 * with the app. Every caller talks to our own API (`ENTRYPOINT`, which is
 * `window.origin` in the browser), so a cross-origin target can only be the
 * result of a tainted URL — block it before the request leaves the browser
 * with the user's session cookies attached (request-forgery defense).
 */
function assertSameOrigin(input: RequestInfo | URL): void {
  if (typeof window === "undefined") return;
  const raw =
    typeof input === "string"
      ? input
      : input instanceof URL
        ? input.href
        : input.url;
  let resolved: URL;
  try {
    resolved = new URL(raw, window.location.href);
  } catch {
    throw new Error("Refusing to fetch an invalid URL.");
  }
  if (resolved.origin !== window.location.origin) {
    throw new Error("Refusing to fetch a cross-origin URL.");
  }
}

/**
 * Thin `fetch` wrapper that wires an `AbortSignal.timeout()` deadline
 * onto the request and re-throws DOMException("TimeoutError") as a
 * human-readable `Error("Network timeout. Please try again.")`. Any
 * caller-provided `signal` is honored as-is — pass your own when you
 * need a longer (or shorter) cap.
 *
 * Only used from the auth / 2FA UI for now where a hung fetch leaves
 * the form stuck in its submitting state; we can broaden the use
 * elsewhere if the same symptom shows up.
 */
export async function fetchWithTimeout(
  input: RequestInfo | URL,
  init: RequestInit & { timeoutMs?: number } = {},
): Promise<Response> {
  assertSameOrigin(input);
  const { timeoutMs = DEFAULT_TIMEOUT_MS, signal, ...rest } = init;
  const effectiveSignal = signal ?? AbortSignal.timeout(timeoutMs);
  try {
    return await fetch(input, { ...rest, signal: effectiveSignal });
  } catch (err) {
    if (
      err instanceof DOMException &&
      (err.name === "TimeoutError" || err.name === "AbortError")
    ) {
      throw new Error("Network timeout. Please try again.");
    }
    throw err;
  }
}
