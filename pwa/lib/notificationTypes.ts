import type { AvatarUser } from "@/components/user/UserAvatar";
import {
  AtSign,
  Bell,
  CheckCircle2,
  ClipboardCheck,
  Gauge,
  MessageSquare,
  Reply,
  UserPlus,
  type LucideIcon,
} from "lucide-react";

/** Server-side notification types (see App\Entity\Notification). */
export type NotificationType =
  | "mention"
  | "reply"
  | "comment"
  | "assigned"
  | "status"
  | "task_reminder"
  | "timesheet_submitted"
  | "timesheet_decided"
  | "budget_alert";

export type NotificationActor = AvatarUser & {
  "@id"?: string;
  id?: string;
};

export interface AppNotification {
  "@id": string;
  id: string;
  type: string;
  title: string;
  body: string | null;
  actor: NotificationActor | null;
  /** Computed deep-link + breadcrumb (entity getters, no extra fetch). */
  targetUrl: string | null;
  targetLabel: string | null;
  contextLabel: string | null;
  readAt: string | null;
  archivedAt: string | null;
  createdAt: string;
}

export interface NotificationCollection {
  member?: AppNotification[];
  "hydra:member"?: AppNotification[];
  totalItems?: number;
  "hydra:totalItems"?: number;
}

export const collectionMembers = (
  data: NotificationCollection,
): AppNotification[] => data.member ?? data["hydra:member"] ?? [];

export const collectionTotal = (data: NotificationCollection): number =>
  data.totalItems ?? data["hydra:totalItems"] ?? 0;

export interface NotificationMeta {
  /** Short uppercase badge label shown in the breadcrumb. */
  label: string;
  icon: LucideIcon;
  /** Tinted pill classes (border + bg + text), dual-mode. */
  badge: string;
  /** Foreground tint for the icon on a neutral surface. */
  icon_color: string;
  /** Left accent-stripe color for the unread state. */
  stripe: string;
}

const FALLBACK: NotificationMeta = {
  label: "UPDATE",
  icon: Bell,
  badge: "border-border bg-muted text-muted-foreground",
  icon_color: "text-muted-foreground",
  stripe: "bg-muted-foreground",
};

export const NOTIFICATION_META: Record<NotificationType, NotificationMeta> = {
  mention: {
    label: "MENTION",
    icon: AtSign,
    badge: "border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-300",
    icon_color: "text-violet-600 dark:text-violet-400",
    stripe: "bg-violet-500",
  },
  reply: {
    label: "REPLY",
    icon: Reply,
    badge: "border-cyan-500/30 bg-cyan-500/10 text-cyan-700 dark:text-cyan-300",
    icon_color: "text-cyan-600 dark:text-cyan-400",
    stripe: "bg-cyan-500",
  },
  comment: {
    label: "COMMENT",
    icon: MessageSquare,
    badge: "border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300",
    icon_color: "text-sky-600 dark:text-sky-400",
    stripe: "bg-sky-500",
  },
  assigned: {
    label: "ASSIGNED",
    icon: UserPlus,
    badge: "border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300",
    icon_color: "text-amber-600 dark:text-amber-400",
    stripe: "bg-amber-500",
  },
  status: {
    label: "STATUS",
    icon: CheckCircle2,
    badge: "border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300",
    icon_color: "text-emerald-600 dark:text-emerald-400",
    stripe: "bg-emerald-500",
  },
  task_reminder: {
    label: "REMINDER",
    icon: Bell,
    badge: "border-orange-500/30 bg-orange-500/10 text-orange-700 dark:text-orange-300",
    icon_color: "text-orange-600 dark:text-orange-400",
    stripe: "bg-orange-500",
  },
  timesheet_submitted: {
    label: "TIMESHEET",
    icon: ClipboardCheck,
    badge: "border-teal-500/30 bg-teal-500/10 text-teal-700 dark:text-teal-300",
    icon_color: "text-teal-600 dark:text-teal-400",
    stripe: "bg-teal-500",
  },
  timesheet_decided: {
    label: "TIMESHEET",
    icon: ClipboardCheck,
    badge: "border-teal-500/30 bg-teal-500/10 text-teal-700 dark:text-teal-300",
    icon_color: "text-teal-600 dark:text-teal-400",
    stripe: "bg-teal-500",
  },
  budget_alert: {
    label: "BUDGET",
    icon: Gauge,
    badge: "border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300",
    icon_color: "text-rose-600 dark:text-rose-400",
    stripe: "bg-rose-500",
  },
};

export const metaFor = (type: string): NotificationMeta =>
  NOTIFICATION_META[type as NotificationType] ?? FALLBACK;

/**
 * The tab/filter taxonomy on the full page. Each maps to one or more
 * server `type` values (passed as repeated `?type=` params).
 */
export const NOTIFICATION_TABS: {
  key: string;
  label: string;
  types: NotificationType[] | null;
}[] = [
  { key: "all", label: "All", types: null },
  { key: "mentions", label: "Mentions", types: ["mention"] },
  { key: "assignments", label: "Assignments", types: ["assigned"] },
  { key: "replies", label: "Replies", types: ["reply", "comment"] },
  {
    key: "updates",
    label: "Updates",
    types: [
      "status",
      "task_reminder",
      "timesheet_submitted",
      "timesheet_decided",
      "budget_alert",
    ],
  },
];
