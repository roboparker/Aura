/**
 * Shared local-date helpers for the calendar surface (`WorkspaceCalendar`,
 * used by both the top-level `/calendar` page and the board Calendar tab).
 * All dates are handled in the browser's local timezone; the canonical key is
 * `YYYY-MM-DD`.
 */

export const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

/** Local `YYYY-MM-DD` key for a date (used to bucket entries per day). */
export const dayKey = (d: Date): string =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

export const addDays = (d: Date, n: number): Date => {
  const next = new Date(d);
  next.setDate(next.getDate() + n);
  return next;
};

/** Sunday that starts the week containing `d`. */
export const startOfWeek = (d: Date): Date => addDays(d, -d.getDay());

/** Parse a `YYYY-MM-DD` key into a local midnight Date. */
export const parseDayKey = (key: string): Date => {
  const [y, m, d] = key.split("-").map(Number);
  return new Date(y, (m ?? 1) - 1, d ?? 1);
};

/** Whole-day difference `to - from` between two `YYYY-MM-DD` keys. */
export const dayKeyDiff = (fromKey: string, toKey: string): number => {
  const from = parseDayKey(fromKey).getTime();
  const to = parseDayKey(toKey).getTime();
  return Math.round((to - from) / 86_400_000);
};

/**
 * The 42-day (6-week) grid covering `anchor`'s month, starting on the Sunday
 * of the week that contains the 1st.
 */
export const monthGridDays = (anchor: Date): Date[] => {
  const firstOfMonth = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
  const gridStart = startOfWeek(firstOfMonth);
  return Array.from({ length: 42 }, (_, i) => addDays(gridStart, i));
};

/** The 7 days of the week containing `anchor` (Sun–Sat). */
export const weekDays = (anchor: Date): Date[] => {
  const start = startOfWeek(anchor);
  return Array.from({ length: 7 }, (_, i) => addDays(start, i));
};
