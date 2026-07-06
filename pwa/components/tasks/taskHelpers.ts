import type { AssigneeOption } from "@/components/tasks/AssigneesCombobox";
import type { Attachment } from "@/components/tasks/AttachmentsPanel";
import type { Comment } from "@/components/common/CommentsPanel";

// Shared task-domain types + pure helpers, extracted from pages/tasks.tsx so
// that page (and the task drawer / row components) don't have to carry ~150
// lines of date math and type declarations inline. Everything here is pure —
// no React, no I/O — so it's safe to unit-test and reuse.

export interface Tag {
  "@id": string;
  id: string;
  title: string;
  color: string;
}

export type RecurrenceFrequency = "daily" | "weekly" | "monthly" | "yearly";

export type Weekday = "MO" | "TU" | "WE" | "TH" | "FR" | "SA" | "SU";

// Picker order is Sun-first to match the mock's S M T W T F S row.
export const WEEKDAYS: Weekday[] = ["SU", "MO", "TU", "WE", "TH", "FR", "SA"];

export const WEEKDAY_LABELS: Record<Weekday, string> = {
  MO: "Mon",
  TU: "Tue",
  WE: "Wed",
  TH: "Thu",
  FR: "Fri",
  SA: "Sat",
  SU: "Sun",
};

// Single-letter labels for the compact day picker (the duplicate S/T are fine
// because position disambiguates, exactly like the mock).
export const WEEKDAY_INITIALS: Record<Weekday, string> = {
  SU: "S",
  MO: "M",
  TU: "T",
  WE: "W",
  TH: "T",
  FR: "F",
  SA: "S",
};

export type MonthlyMode = "day" | "weekday";

export type RecurrenceEnds =
  | { type: "until"; until: string } // YYYY-MM-DD
  | { type: "count"; count: number };

// The rule mirrors the API's expanded JSON shape. Only frequency + interval
// are required; the rest specialise weekly (byDay) and monthly (monthlyMode +
// bySetPos), plus an optional end condition. A bare {frequency, interval} is
// still valid, so legacy rows and the simple list picker keep working.
export interface RecurrenceRule {
  frequency: RecurrenceFrequency;
  interval: number;
  byDay?: Weekday[];
  monthlyMode?: MonthlyMode;
  bySetPos?: number; // 1..5 or -1 (last)
  ends?: RecurrenceEnds | null;
}

export type ReminderUnit = "minutes" | "hours" | "days";

// A reminder is either relative to the due date or pinned to an absolute
// timestamp; either may "repeat daily until done". Mirrors the API shape.
export type Reminder =
  | { type: "relative"; value: number; unit: ReminderUnit; repeat: boolean }
  | { type: "absolute"; at: string; repeat: boolean };

// Canonical key for a reminder — mirrors ReminderScheduler::canonicalKey on
// the API so the UI can dedup reminders consistently. Ignores the `repeat`
// flag (same as the server) so two reminders match regardless.
export const reminderKey = (r: Reminder): string =>
  r.type === "relative"
    ? `rel:${r.value}:${r.unit}`
    : `abs:${r.at}`;

const REMINDER_UNIT_SINGULAR: Record<ReminderUnit, string> = {
  minutes: "minute",
  hours: "hour",
  days: "day",
};

const absoluteReminderFormatter = new Intl.DateTimeFormat(undefined, {
  month: "short",
  day: "numeric",
  hour: "numeric",
  minute: "2-digit",
});

// Human label for one reminder, e.g. "30 minutes before due" / "On Apr 2,
// 9:00 AM". Used in the drawer list + the read-only summaries.
export const describeReminder = (r: Reminder): string => {
  if (r.type === "relative") {
    if (r.value === 0) return "When due";
    const unit = REMINDER_UNIT_SINGULAR[r.unit];
    return `${r.value} ${unit}${r.value === 1 ? "" : "s"} before due`;
  }
  const at = new Date(r.at);
  return Number.isNaN(at.getTime())
    ? "At a set time"
    : `On ${absoluteReminderFormatter.format(at)}`;
};

export const summarizeReminders = (reminders: Reminder[] | null): string => {
  if (!reminders || reminders.length === 0) return "";
  if (reminders.length === 1) return describeReminder(reminders[0]);
  return `${reminders.length} reminders`;
};

export interface Task {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  completedOn: string | null;
  dueDate: string | null;
  // Embedded user shape under `task:read` (the User entity exposes its
  // basic fields in that group). Used here only to widen comment
  // delete-rights for the task owner without an extra fetch.
  owner: { "@id": string };
  recurrenceRule: RecurrenceRule | null;
  reminders: Reminder[] | null;
  // Attachments embed inline under `task:read`. The PWA uploads via
  // `POST /media-objects?kind=attachment`, then PATCHes the IRI here.
  attachments: Attachment[];
  position: number;
  tags: Tag[];
  assignees: AssigneeOption[];
  // The API serializes Task.board as a bare IRI string under `task:read`.
  // null means "personal task" — only the owner is assignable.
  board: string | null;
}

export const FREQUENCY_LABELS: Record<RecurrenceFrequency, string> = {
  daily: "day",
  weekly: "week",
  monthly: "month",
  yearly: "year",
};

// 1 → "1st", 2 → "2nd", -1 → "last" (for monthly "2nd Thursday").
export const ordinalLabel = (n: number): string => {
  if (n === -1) return "last";
  const s = ["th", "st", "nd", "rd"];
  const v = n % 100;
  return `${n}${s[(v - 20) % 10] ?? s[v] ?? s[0]}`;
};

// Compact one-line summary matching the mock chips: "Daily · every 2 days",
// "Weekly · Mon / Wed / Fri", "Monthly · 2nd Thursday".
export const formatRecurrenceSummary = (rule: RecurrenceRule): string => {
  switch (rule.frequency) {
    case "daily":
      return rule.interval === 1 ? "Daily" : `Daily · every ${rule.interval} days`;
    case "weekly": {
      const base = rule.interval === 1 ? "Weekly" : `Weekly · every ${rule.interval} weeks`;
      if (rule.byDay && rule.byDay.length > 0) {
        const days = WEEKDAYS.filter((d) => rule.byDay?.includes(d))
          .map((d) => WEEKDAY_LABELS[d])
          .join(" / ");
        return `${base} · ${days}`;
      }
      return base;
    }
    case "monthly": {
      if (rule.monthlyMode === "weekday" && rule.byDay && rule.byDay[0] && rule.bySetPos) {
        return `Monthly · ${ordinalLabel(rule.bySetPos)} ${WEEKDAY_LABELS[rule.byDay[0]]}`;
      }
      return rule.interval === 1 ? "Monthly" : `Monthly · every ${rule.interval} months`;
    }
    case "yearly":
      return rule.interval === 1 ? "Yearly" : `Yearly · every ${rule.interval} years`;
    default:
      return "Repeats";
  }
};

// "manual" maps to the persisted `position` order — drag-to-reorder is only
// active in this mode because anything else would snap rows back the moment
// we re-sorted. The other keys are derived sort orders that don't touch the
// underlying tasks array, just the rendered view.
export type SortKey = "manual" | "completed" | "title" | "due";
export type SortDir = "asc" | "desc";

export interface SortState {
  key: SortKey;
  dir: SortDir;
}

export const DEFAULT_SORT: SortState = { key: "manual", dir: "asc" };

// Stored as ISO datetime on the wire but only the calendar day matters —
// we persist UTC midnight on the picked day so round-trips are stable
// across timezones. Read back via the YYYY-MM-DD slice and rebuild as a
// local Date so the calendar / formatter render the day the user picked.
export const isoToLocalDate = (iso: string | null): Date | undefined => {
  if (!iso) return undefined;
  const [year, month, day] = iso.slice(0, 10).split("-").map(Number);
  if (!year || !month || !day) return undefined;
  return new Date(year, month - 1, day);
};

export const localDateToIso = (date: Date): string => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}T00:00:00+00:00`;
};

const dueDateFormatter = new Intl.DateTimeFormat(undefined, {
  year: "numeric",
  month: "short",
  day: "numeric",
});

export const formatDueDate = (iso: string | null): string => {
  const date = isoToLocalDate(iso);
  return date ? dueDateFormatter.format(date) : "";
};

// Local-midnight "today" so day comparisons line up with the dates the picker
// stores (also local-midnight). Cheaper than a fresh Date() per row.
export const todayLocalMidnight = (): Date => {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), now.getDate());
};

export type DueDateStatus = "overdue" | "today" | "future" | "none";

export const dueDateStatus = (iso: string | null, completed: boolean): DueDateStatus => {
  if (completed || !iso) return "none";
  const due = isoToLocalDate(iso);
  if (!due) return "none";
  const today = todayLocalMidnight();
  if (due.getTime() < today.getTime()) return "overdue";
  if (due.getTime() === today.getTime()) return "today";
  return "future";
};

export const addDays = (date: Date, days: number): Date => {
  const next = new Date(date);
  next.setDate(next.getDate() + days);
  return next;
};

export const addMonths = (date: Date, months: number): Date => {
  const next = new Date(date);
  next.setMonth(next.getMonth() + months);
  return next;
};

// Strip the most common markdown punctuation so the description in the
// dedicated sub-row reads as plain text. We keep paragraph breaks via `\n`
// so multi-paragraph descriptions still feel structured. A real markdown
// renderer (`MarkdownView`) can replace this later if we want bold/links.
export const plainTextDescription = (markdown: string | null): string => {
  if (!markdown) return "";
  return markdown
    .replace(/`{3}[\s\S]*?`{3}/g, "")
    .replace(/`([^`]+)`/g, "$1")
    .replace(/^[#>*_~`\-]+\s*/gm, "")
    .replace(/\!?\[([^\]]*)\]\([^)]*\)/g, "$1")
    .replace(/[*_~]+/g, "")
    .replace(/[ \t]+/g, " ")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
};

// Drops one comment from the local list. Was a recursive subtree
// pruner pre-#228 when comments had a reply tree; the unified model
// is flat, so a single ID filter is enough.
export const removeComment = (list: Comment[], removedIri: string): Comment[] =>
  list.filter((c) => c["@id"] !== removedIri);
