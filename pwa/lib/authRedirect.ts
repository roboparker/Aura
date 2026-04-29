/**
 * Helpers for the post-authentication "send the user back where they
 * came from" flow.
 *
 * Restricted pages should call `signinHrefForCurrent(router.asPath)` when
 * redirecting an unauthenticated visitor — that bakes a `?next=<path>`
 * onto `/signin` so the auth screens can route them back after login.
 *
 * `safeNextPath()` is the corresponding consumer: it accepts only same-
 * origin, root-relative paths, so a malicious `?next=//evil.com/x` (or
 * `next=https://evil.com`) can't turn the auth screens into an open
 * redirector.
 */

const FALLBACK_PATH = "/account";

// Arbitrary base used to round-trip user-supplied `next` through the
// URL parser. The host/protocol are bogus so we can detect any value
// that resolves off-origin (anything except this exact origin is
// rejected). The URL constructor handles all the edge cases we'd
// otherwise have to hand-code: backslashes (browsers normalise these
// to forward slashes for http(s) URLs), tabs/CR/LF in the input,
// percent-encoded weirdness, scheme-relative URLs, etc.
const SANITIZER_BASE = "https://aura.invalid";

/**
 * Whether `candidate` is a safe relative path we can redirect to.
 * Rejects:
 *   - Non-strings / empty strings
 *   - Anything that doesn't start with a single `/`
 *     (covers absolute URLs, `javascript:`/`data:` URIs, bare paths)
 *   - Protocol-relative URLs (`//host/...`)
 *   - Anything the URL parser resolves off the sanitizer base
 *     (`/\\host/...` after browser slash-normalisation, etc.)
 */
export const isSafeNextPath = (candidate: unknown): candidate is string => {
  if (typeof candidate !== "string") return false;
  if (candidate === "") return false;
  if (!candidate.startsWith("/")) return false;
  if (candidate.startsWith("//")) return false;
  if (candidate.startsWith("/\\")) return false;
  try {
    const parsed = new URL(candidate, SANITIZER_BASE);
    return parsed.origin === SANITIZER_BASE;
  } catch {
    return false;
  }
};

/**
 * Returns a same-origin path safe to hand to `router.push` / `router.replace`.
 *
 * Round-trips the candidate through `new URL(...)` and rebuilds the
 * navigable string from the parser's own `pathname + search + hash`.
 * That has two upsides:
 *
 * 1. It guarantees we only ever return values that the parser agrees
 *    are same-origin paths — no clever string trick can sneak through.
 * 2. The returned string is constructed entirely from URL-parser
 *    outputs, which static analysis (CodeQL's `js/client-side-url-redirect`
 *    in particular) recognises as a sanitised, navigable path. The raw
 *    candidate string never reaches the navigation API.
 */
export const safeNextPath = (
  candidate: unknown,
  fallback: string = FALLBACK_PATH,
): string => {
  if (!isSafeNextPath(candidate)) return fallback;
  try {
    const parsed = new URL(candidate, SANITIZER_BASE);
    if (parsed.origin !== SANITIZER_BASE) return fallback;
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    return fallback;
  }
};

/**
 * Returns `/signin?next=<path>` if `currentPath` is a safe path worth
 * preserving, otherwise plain `/signin`. We strip the auth pages
 * themselves so a back-from-auth bounce doesn't loop.
 */
export const signinHrefForCurrent = (currentPath: string | undefined): string => {
  if (!isSafeNextPath(currentPath)) return "/signin";
  if (currentPath.startsWith("/signin") || currentPath.startsWith("/signup")) {
    return "/signin";
  }
  return `/signin?next=${encodeURIComponent(currentPath)}`;
};
