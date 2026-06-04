// Localised "2h ago" / "in 5 minutes" formatter shared across the
// listings (spaces grid, comments, activity, etc.). Lives in lib/ so
// every consumer reaches for the same implementation rather than
// growing parallel copies — there were five before this extraction.

const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });

/**
 * True when `iso` is within `withinMs` of now. Lives here (rather than
 * inline in a component) so callers don't trip the react-hooks purity
 * rule by calling `Date.now()` during render.
 */
export function isRecent(iso: string | Date, withinMs: number): boolean {
  const ts = iso instanceof Date ? iso.getTime() : new Date(iso).getTime();
  if (Number.isNaN(ts)) return false;
  return Date.now() - ts < withinMs;
}

export function formatRelative(iso: string | Date): string {
  const ts = iso instanceof Date ? iso.getTime() : new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diffSec = Math.round((ts - Date.now()) / 1000);
  const abs = Math.abs(diffSec);
  if (abs < 60) return RELATIVE.format(diffSec, "second");
  if (abs < 3600) return RELATIVE.format(Math.round(diffSec / 60), "minute");
  if (abs < 86400) return RELATIVE.format(Math.round(diffSec / 3600), "hour");
  if (abs < 2592000) return RELATIVE.format(Math.round(diffSec / 86400), "day");
  if (abs < 31536000) return RELATIVE.format(Math.round(diffSec / 2592000), "month");
  return RELATIVE.format(Math.round(diffSec / 31536000), "year");
}
