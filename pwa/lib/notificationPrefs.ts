import type { NotificationChannel } from "@/contexts/AuthContext";

/**
 * Notification matrix row metadata, mirrored from the backend
 * `User::NOTIFICATION_MATRIX_ROWS` + DEFAULT_PREFERENCES. Keep in lock-step.
 */
export interface NotificationRow {
  key: string;
  label: string;
  description: string;
}

export const NOTIFICATION_ROWS: NotificationRow[] = [
  {
    key: "mentions",
    label: "Mentions",
    description: "When someone @-mentions you in a task, comment, or page.",
  },
  {
    key: "assigned",
    label: "Assigned to me",
    description: "A task is assigned to you, or you're added as a reviewer.",
  },
  {
    key: "comments",
    label: "Comments on my tasks",
    description: "New top-level comments on tasks you own or follow.",
  },
  {
    key: "replies",
    label: "Replies in threads I follow",
    description: "Replies inside discussion threads you're subscribed to.",
  },
  {
    key: "status",
    label: "Status changes on my tasks",
    description: "Open → in review → done on tasks where you're assignee or owner.",
  },
  {
    key: "space-invites",
    label: "Space invites",
    description: "When you're invited to a new space or your role changes.",
  },
];

export const DEFAULT_NOTIFICATION_MATRIX: Record<string, NotificationChannel> = {
  mentions: { inApp: true, email: true },
  assigned: { inApp: true, email: true },
  comments: { inApp: true, email: false },
  replies: { inApp: true, email: true },
  status: { inApp: true, email: false },
  "space-invites": { inApp: true, email: true },
};

/** Every row on, both channels. */
export const allOnMatrix = (): Record<string, NotificationChannel> =>
  Object.fromEntries(
    NOTIFICATION_ROWS.map((r) => [r.key, { inApp: true, email: true }]),
  );
