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

/**
 * Whether `candidate` is a safe relative path we can redirect to.
 * Rejects protocol-relative URLs (`//host/...`), absolute URLs, and
 * anything that doesn't start with a single `/`.
 */
export const isSafeNextPath = (candidate: unknown): candidate is string => {
  if (typeof candidate !== "string") return false;
  if (!candidate.startsWith("/")) return false;
  if (candidate.startsWith("//")) return false;
  return true;
};

/** Returns the value of `?next` if it's safe, else the fallback. */
export const safeNextPath = (
  candidate: unknown,
  fallback: string = FALLBACK_PATH,
): string => (isSafeNextPath(candidate) ? candidate : fallback);

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
