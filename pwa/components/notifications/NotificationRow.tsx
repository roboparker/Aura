import { Archive, Check, ExternalLink } from "lucide-react";
import UserAvatar from "@/components/user/UserAvatar";
import { Checkbox } from "@/components/ui/checkbox";
import { formatRelative } from "@/lib/relativeTime";
import { metaFor, type AppNotification } from "@/lib/notificationTypes";
import { cn } from "@/lib/utils";

/**
 * Colored-dot type badge (COMMENT / MENTION / …). Exported so the bell
 * dropdown shares the inbox's visual language.
 */
export const NotificationTypeBadge = ({ type }: { type: string }) => {
  const meta = metaFor(type);
  return (
    <span
      className={cn(
        "inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide border",
        meta.badge,
      )}
    >
      {meta.label}
    </span>
  );
};

interface Props {
  notification: AppNotification;
  selectable?: boolean;
  selected?: boolean;
  onToggleSelect?: () => void;
  onMarkRead?: (n: AppNotification) => void;
  onArchive?: (n: AppNotification) => void;
  onOpen: (n: AppNotification) => void;
}

/**
 * One inbox row in three states (mockup "Three states, same row
 * component"): unread = accent stripe + dot + full color; read = dimmed,
 * no rail; selected = filled checkbox + row tint. Quick actions (mark
 * read / archive / open) slide in on hover so the resting state stays
 * scannable. Clicking the row opens the linked entity.
 */
const NotificationRow = ({
  notification,
  selectable = false,
  selected = false,
  onToggleSelect,
  onMarkRead,
  onArchive,
  onOpen,
}: Props) => {
  const meta = metaFor(notification.type);
  const unread = notification.readAt === null;
  const Icon = meta.icon;

  return (
    <li
      className={cn(
        "group relative flex items-start gap-3 px-4 py-3 transition-colors",
        selected ? "bg-primary/5" : "hover:bg-muted/40",
        !unread && !selected && "opacity-60",
      )}
      data-testid="notification-item"
    >
      {/* Unread accent stripe */}
      {unread && (
        <span
          className={cn("absolute inset-y-0 left-0 w-0.5", meta.stripe)}
          aria-hidden
        />
      )}

      {selectable && (
        <Checkbox
          checked={selected}
          onCheckedChange={() => onToggleSelect?.()}
          className="mt-1"
          aria-label="Select notification"
          data-testid="notification-select"
        />
      )}

      {/* Unread dot */}
      <span className="mt-2 flex w-1.5 shrink-0 justify-center">
        {unread && (
          <span className={cn("h-1.5 w-1.5 rounded-full", meta.stripe)} aria-hidden />
        )}
      </span>

      {/* Actor avatar with a small type-icon corner */}
      <div className="relative shrink-0">
        {notification.actor ? (
          <UserAvatar user={notification.actor} size="sm" />
        ) : (
          <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-muted">
            <Icon className={cn("h-4 w-4", meta.icon_color)} />
          </span>
        )}
        <span className="absolute -bottom-1 -right-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-background ring-2 ring-background">
          <Icon className={cn("h-3 w-3", meta.icon_color)} />
        </span>
      </div>

      {/* Content */}
      <button
        type="button"
        onClick={() => onOpen(notification)}
        className="min-w-0 flex-1 text-left"
        data-testid="notification-open"
      >
        <div className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          {notification.contextLabel && (
            <>
              <span className="truncate font-mono">{notification.contextLabel}</span>
              <span aria-hidden>›</span>
            </>
          )}
          <NotificationTypeBadge type={notification.type} />
        </div>
        <p className={cn("mt-0.5 text-sm", unread ? "font-medium text-foreground" : "text-foreground")}>
          {notification.title}
        </p>
        {notification.body && (
          <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
            {notification.body}
          </p>
        )}
      </button>

      {/* Time + hover quick actions */}
      <div className="flex shrink-0 flex-col items-end gap-1">
        <span className="text-[11px] text-muted-foreground">
          {formatRelative(notification.createdAt)}
        </span>
        <div className="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
          {unread && onMarkRead && (
            <button
              type="button"
              title="Mark read"
              onClick={() => onMarkRead(notification)}
              className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
              data-testid="notification-mark-read"
            >
              <Check className="h-3.5 w-3.5" />
            </button>
          )}
          {onArchive && (
            <button
              type="button"
              title="Archive"
              onClick={() => onArchive(notification)}
              className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
              data-testid="notification-archive"
            >
              <Archive className="h-3.5 w-3.5" />
            </button>
          )}
          <button
            type="button"
            title="Open"
            onClick={() => onOpen(notification)}
            className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
          >
            <ExternalLink className="h-3.5 w-3.5" />
          </button>
        </div>
      </div>
    </li>
  );
};

export default NotificationRow;
