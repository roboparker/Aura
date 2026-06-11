// Product analytics via self-hosted Umami (see docs/developer/analytics.md).
//
// The tracker script is injected by _app.tsx only when
// NEXT_PUBLIC_UMAMI_WEBSITE_ID is set (it's baked in at build time, so prod
// gets it from pwa/.env.production). Pageviews are tracked automatically by
// the script, including SPA route changes; call trackEvent() for product
// events. Everything no-ops when the tracker is absent — dev without
// analytics, tests, ad-blocked clients — so call sites never need to guard.

type UmamiEventData = Record<string, string | number | boolean>;

declare global {
  interface Window {
    umami?: { track: (event: string, data?: UmamiEventData) => void };
  }
}

export const UMAMI_WEBSITE_ID = process.env.NEXT_PUBLIC_UMAMI_WEBSITE_ID;

// Served first-party through Caddy (api/frankenphp/Caddyfile proxies
// /umami/script.js and /umami/api/send to the umami container), so the
// tracker shares the app origin instead of pointing at a blockable
// third-party domain.
export const UMAMI_SCRIPT_SRC =
  process.env.NEXT_PUBLIC_UMAMI_SCRIPT_SRC || "/umami/script.js";

// Event names are kebab-case verbs of the thing that happened. Keep payloads
// coarse (counts, booleans, category names) — never user content or emails.
export function trackEvent(name: string, data?: UmamiEventData): void {
  if (typeof window === "undefined") return;
  try {
    window.umami?.track(name, data);
  } catch {
    // Analytics must never break the app.
  }
}
