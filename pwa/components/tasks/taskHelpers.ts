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

export interface RecurrenceRule {
  frequency: RecurrenceFrequency;
  interval: number;
}

// Allowlist of reminder offsets the API accepts on Task.reminders. Kept in
// the same order the picker renders so checkbox state ↔ JSON array stay
// trivially aligned.
export const REMINDER_OFFSETS = ["15m", "1h", "1d"] as const;
export type ReminderOffset = (typeof REMINDER_OFFSETS)[number];
export const REMINDER_LABELS: Record<ReminderOffset, string> = {
  "15m": "15 minutes before",
  "1h": "1 hour before",
  "1d": "1 day before",
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
  reminders: ReminderOffset[] | null;
  // Attachments embed inline under `task:read`. The PWA uploads via
  // `POST /media-objects?kind=attachment`, then PATCHes the IRI here.
  attachments: Attachment[];
  position: number;
  tags: Tag[];
  assignees: AssigneeOption[];
  // The API serializes Task.project as a bare IRI string under `task:read`.
  // null means "personal task" — only the owner is assignable.
  project: string | null;
}

export const FREQUENCY_LABELS: Record<RecurrenceFrequency, string> = {
  daily: "day",
  weekly: "week",
  monthly: "month",
  yearly: "year",
};

export const formatRecurrenceSummary = (rule: RecurrenceRule): string => {
  const noun = FREQUENCY_LABELS[rule.frequency];
  if (rule.interval === 1) return `Every ${noun}`;
  return `Every ${rule.interval} ${noun}s`;
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
