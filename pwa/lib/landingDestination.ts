/**
 * The post-login "Start page" preference: where a fresh sign-in (one with
 * no explicit `?next=` deep link) lands. Mirrors the API's
 * `User::DEFAULT_PREFERENCES['landing']` shape and whitelist.
 *
 * Deep links always win — these helpers only feed `safeNextPath`'s
 * fallback, so a `?next=` target is unaffected.
 */
import type { LandingPage, LandingPreference, UserPreferences } from "@/contexts/AuthContext";

export const LANDING_PAGES: LandingPage[] = [
  "space",
  "tasks",
  "notifications",
  "spaces",
];

/** Default matches #405: workspace home with the personal space active. */
export const DEFAULT_LANDING: LandingPreference = { page: "space", spaceId: null };

/** Route each landing page maps to. `space` lands on the workspace home. */
const PAGE_PATHS: Record<LandingPage, string> = {
  tasks: "/tasks",
  notifications: "/notifications",
  spaces: "/spaces",
  space: "/projects",
};

/** Short labels for the Settings "Start page" control. */
export const LANDING_PAGE_LABELS: Record<LandingPage, string> = {
  space: "A space",
  tasks: "My tasks",
  notifications: "My notifications",
  spaces: "All spaces",
};

/**
 * Read a safe `LandingPreference` off a (possibly partial / legacy)
 * preferences object. Unknown pages fall back to the default so a stale
 * stored value can never strand the user.
 */
export const readLanding = (
  preferences: Pick<UserPreferences, "landing"> | undefined | null,
): LandingPreference => {
  const landing = preferences?.landing;
  if (!landing || typeof landing !== "object") return DEFAULT_LANDING;
  const page = LANDING_PAGES.includes(landing.page as LandingPage)
    ? landing.page
    : DEFAULT_LANDING.page;
  return {
    page,
    spaceId: page === "space" ? landing.spaceId ?? null : null,
  };
};

/** The route a given landing preference points at. */
export const landingPathFor = (landing: LandingPreference): string =>
  PAGE_PATHS[landing.page] ?? PAGE_PATHS[DEFAULT_LANDING.page];
