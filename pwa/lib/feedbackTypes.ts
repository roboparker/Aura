import type { AvatarUser } from "@/components/user/UserAvatar";

/**
 * Shared types + display metadata for the in-app feedback board. The
 * server is the source of truth for the allowed values (App\Entity\Feedback);
 * these mirror them for the PWA. Kept free of any `node:` imports so it's
 * safe to import from client components.
 */

export type FeedbackType = "bug" | "feature";

export type FeedbackStatus =
  | "open"
  | "planned"
  | "in_progress"
  | "shipped"
  | "declined";

/** The ticket owner, embedded under feedback:read for chip rendering. */
export type FeedbackOwner = AvatarUser & { "@id": string; id: string };

export interface Feedback {
  "@id": string;
  id: string;
  ticketNumber: number;
  title: string;
  description: string;
  type: FeedbackType;
  status: FeedbackStatus;
  owner: FeedbackOwner;
  createdAt: string;
  updatedAt: string | null;
  /** Net vote score (sum of +1 / -1). */
  score: number;
  /** The current user's own vote: 1, -1, or 0. */
  myVote: number;
  commentCount: number;
}

export const TYPE_ORDER: FeedbackType[] = ["bug", "feature"];

export const TYPE_META: Record<
  FeedbackType,
  { label: string; badgeClass: string }
> = {
  bug: {
    label: "Bug",
    badgeClass:
      "bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300",
  },
  feature: {
    label: "Feature",
    badgeClass:
      "bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300",
  },
};

export const STATUS_ORDER: FeedbackStatus[] = [
  "open",
  "planned",
  "in_progress",
  "shipped",
  "declined",
];

export const STATUS_META: Record<
  FeedbackStatus,
  { label: string; badgeClass: string }
> = {
  open: {
    label: "Open",
    badgeClass:
      "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
  },
  planned: {
    label: "Planned",
    badgeClass:
      "bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300",
  },
  in_progress: {
    label: "In progress",
    badgeClass:
      "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300",
  },
  shipped: {
    label: "Shipped",
    badgeClass:
      "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300",
  },
  declined: {
    label: "Declined",
    badgeClass:
      "bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300",
  },
};

export const typeLabel = (t: FeedbackType): string => TYPE_META[t].label;
export const statusLabel = (s: FeedbackStatus): string => STATUS_META[s].label;

/** Relative-time formatter shared by the board + detail surfaces. */
const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });

export const formatRelative = (iso: string): string => {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diffSec = Math.round((ts - Date.now()) / 1000);
  const abs = Math.abs(diffSec);
  if (abs < 60) return RELATIVE.format(diffSec, "second");
  if (abs < 3600) return RELATIVE.format(Math.round(diffSec / 60), "minute");
  if (abs < 86400) return RELATIVE.format(Math.round(diffSec / 3600), "hour");
  if (abs < 2592000) return RELATIVE.format(Math.round(diffSec / 86400), "day");
  if (abs < 31536000)
    return RELATIVE.format(Math.round(diffSec / 2592000), "month");
  return RELATIVE.format(Math.round(diffSec / 31536000), "year");
};
