/**
 * Global "the session died" detector.
 *
 * Sessions on the API are server-side PHP sessions with no long lifetime,
 * so an authenticated tab can have its cookie silently stop working — idle
 * garbage collection, or a deploy that swaps the container out from under
 * it. When that happens the next API call comes back 401. Without this the
 * calling page just hangs on its loading state and the user can't tell
 * they've been logged out until they manually refresh (the symptom that
 * motivated #-session-expiry).
 *
 * This installs a one-time `window.fetch` wrapper that watches every
 * same-origin API response. The first time an *armed* session sees a 401
 * from a non-auth endpoint, it fires the registered handler exactly once —
 * AuthContext clears the user and redirects to the "session expired"
 * sign-in screen. Every API call in the app ultimately routes through
 * `window.fetch` (raw `fetch`, `fetchWithTimeout`, and `apiClient` alike),
 * so wrapping it once covers every call site without touching them.
 *
 * "Armed" = we currently believe we have a logged-in user. That guard is
 * what keeps a 401 on the pre-login `/api/me` probe (a normal,
 * unauthenticated response) from ever reading as an expiry.
 */

let armed = false;
let fired = false;
let handler: (() => void) | null = null;
let installed = false;

/** Flag we stamp on our wrapper so HMR / double-imports can't nest it. */
const WRAPPED_FLAG = "__madoriSessionWrapped";

type TaggedFetch = typeof window.fetch & { [WRAPPED_FLAG]?: boolean };

/**
 * Auth endpoints legitimately answer 401 (bad credentials, a 2FA step, a
 * failed step-up) without meaning the established session expired — so a
 * 401 from any of them must never trip the detector.
 */
const isExemptPath = (pathname: string): boolean => pathname.startsWith("/auth/");

/** Register (or clear) the callback fired when an armed session expires. */
export function setSessionExpiredHandler(fn: (() => void) | null): void {
  handler = fn;
}

/**
 * Toggle whether 401s should be treated as an expiry. AuthContext arms
 * this whenever a user is present and disarms (which also re-primes the
 * one-shot guard) when not, so the detector only ever acts on a session
 * that was actually established.
 */
export function armSessionExpiry(value: boolean): void {
  armed = value;
  if (!value) fired = false;
}

function notifySessionExpired(): void {
  if (!armed || fired || handler === null) return;
  fired = true;
  handler();
}

function resolveUrl(input: RequestInfo | URL): URL | null {
  const raw =
    typeof input === "string"
      ? input
      : input instanceof URL
        ? input.href
        : input.url;
  try {
    return new URL(raw, window.location.href);
  } catch {
    return null;
  }
}

/** Install the fetch interceptor exactly once (browser only). */
export function installSessionExpiryInterceptor(): void {
  if (installed || typeof window === "undefined") return;
  installed = true;
  if ((window.fetch as TaggedFetch)[WRAPPED_FLAG]) return;

  const original = window.fetch.bind(window);
  const wrapped = async (
    input: RequestInfo | URL,
    init?: RequestInit,
  ): Promise<Response> => {
    const res = await original(input, init);
    // Reading `.status` doesn't consume the body, so the Response is
    // handed back untouched for the real caller to parse.
    if (res.status === 401) {
      const url = resolveUrl(input);
      if (
        url !== null &&
        url.origin === window.location.origin &&
        !isExemptPath(url.pathname)
      ) {
        notifySessionExpired();
      }
    }
    return res;
  };
  (wrapped as TaggedFetch)[WRAPPED_FLAG] = true;
  window.fetch = wrapped as typeof window.fetch;
}
