/** Wire shapes + helpers for `/time_entries` (Harvest model). */

export interface TimeEntry {
  "@id": string;
  id: string;
  space: string;
  engagement: string | null;
  category: string | null;
  user: string;
  description: string | null;
  startedAt: string;
  endedAt: string | null;
  durationSeconds: number | null;
  billable: boolean;
  rateAmount: number | null;
  rateCurrency: string | null;
  billedAt: string | null;
  createdAt: string;
  updatedAt: string | null;
}

export interface TimeEntryCollection {
  member?: TimeEntry[];
  "hydra:member"?: TimeEntry[];
}

/** A category as returned by the engagement picker. */
export interface CategoryOption {
  "@id": string;
  id: string;
  name: string;
  rateAmount: number;
}

/**
 * A engagement + its categories from `GET /spaces/{id}/engagement-options`
 * — the minimal member-facing picker for time tracking (no invoices-gated read).
 */
export interface EngagementOption {
  "@id": string;
  id: string;
  name: string;
  currency: string | null;
  categories: CategoryOption[];
}

/** A running timer has no end time yet. */
export const isRunning = (entry: TimeEntry): boolean => entry.endedAt === null;

/** Seconds between start and end (or now, for a running timer). */
export const elapsedSeconds = (entry: TimeEntry, now: number = Date.now()): number => {
  if (entry.durationSeconds !== null) {
    return entry.durationSeconds;
  }
  const start = new Date(entry.startedAt).getTime();
  return Math.max(0, Math.floor((now - start) / 1000));
};

/** Format a duration in seconds as "1h 30m" / "45m" / "12s". */
export const formatDuration = (totalSeconds: number): string => {
  const s = Math.max(0, Math.floor(totalSeconds));
  const hours = Math.floor(s / 3600);
  const minutes = Math.floor((s % 3600) / 60);
  const seconds = s % 60;
  if (hours > 0) {
    return minutes > 0 ? `${hours}h ${minutes}m` : `${hours}h`;
  }
  if (minutes > 0) {
    return `${minutes}m`;
  }
  return `${seconds}s`;
};

/**
 * Format a duration as a timesheet cell value — "1:30" (h:mm). Empty string
 * for zero so an untouched grid cell stays blank.
 */
export const formatCellDuration = (totalSeconds: number): string => {
  const s = Math.max(0, Math.round(totalSeconds));
  if (s === 0) return "";
  const hours = Math.floor(s / 3600);
  const minutes = Math.round((s % 3600) / 60);
  return `${hours}:${minutes.toString().padStart(2, "0")}`;
};

/**
 * Parse a timesheet cell input into seconds. Accepts "1:30" (h:mm), "1.5"
 * (decimal hours), and "" / "0" (zero). Returns null for anything malformed
 * or out of range (an entry can't span midnight, so < 24h).
 */
export const parseDurationInput = (raw: string): number | null => {
  const value = raw.trim();
  if (value === "" || value === "0") return 0;
  let seconds: number;
  const colon = /^(\d{1,2}):([0-5]?\d)$/.exec(value);
  if (colon) {
    seconds = parseInt(colon[1], 10) * 3600 + parseInt(colon[2], 10) * 60;
  } else if (/^\d+([.,]\d+)?$/.test(value)) {
    seconds = Math.round(parseFloat(value.replace(",", ".")) * 3600);
  } else {
    return null;
  }
  if (seconds <= 0 || seconds >= 24 * 3600) return null;
  return seconds;
};

/** A stopwatch-style "1:30:05" clock for a live running timer. */
export const formatClock = (totalSeconds: number): string => {
  const s = Math.max(0, Math.floor(totalSeconds));
  const hours = Math.floor(s / 3600);
  const minutes = Math.floor((s % 3600) / 60);
  const seconds = s % 60;
  const mm = minutes.toString().padStart(2, "0");
  const ss = seconds.toString().padStart(2, "0");
  return hours > 0 ? `${hours}:${mm}:${ss}` : `${minutes}:${ss}`;
};
