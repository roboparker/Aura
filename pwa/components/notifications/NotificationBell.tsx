import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/router";
import Link from "next/link";
import { Bell, Settings } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import UserAvatar from "@/components/user/UserAvatar";
import { NotificationTypeBadge } from "@/components/notifications/NotificationRow";
import { useNotificationLiveUpdates } from "@/lib/useNotificationLiveUpdates";
import { markReadRequest, bulkMarkReadRequest } from "@/lib/useNotifications";
import { formatRelative, timeGroup, type TimeGroup } from "@/lib/relativeTime";
import {
  collectionMembers,
  collectionTotal,
  metaFor,
  type AppNotification,
  type NotificationCollection,
} from "@/lib/notificationTypes";
import { cn } from "@/lib/utils";

const POLL_INTERVAL_MS = 60_000;
const MAX_VISIBLE = 12;
const GROUP_ORDER: TimeGroup[] = ["Today", "This week", "Earlier"];

interface NotificationBellProps {
  /** Hides the bell when the user isn't logged in; pauses fetching too. */
  enabled: boolean;
}

const NotificationBell = ({ enabled }: NotificationBellProps) => {
  const router = useRouter();
  const [items, setItems] = useState<AppNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [open, setOpen] = useState(false);
  const [tab, setTab] = useState<"all" | "unread">("all");

  const fetchAll = useCallback(async () => {
    if (!enabled) return;
    try {
      const [listRes, countRes] = await Promise.all([
        fetch(
          `${ENTRYPOINT}/notifications?exists[archivedAt]=false&itemsPerPage=${MAX_VISIBLE}`,
          { credentials: "include", headers: { Accept: "application/ld+json" } },
        ),
        fetch(
          `${ENTRYPOINT}/notifications?exists[readAt]=false&exists[archivedAt]=false&itemsPerPage=1`,
          { credentials: "include", headers: { Accept: "application/ld+json" } },
        ),
      ]);
      if (listRes.ok) {
        const data: NotificationCollection = await listRes.json();
        setItems(collectionMembers(data));
      }
      if (countRes.ok) {
        const data: NotificationCollection = await countRes.json();
        setUnreadCount(collectionTotal(data));
      }
    } catch {
      // Chrome convenience — stay quiet and retry on the next tick.
    }
  }, [enabled]);

  useEffect(() => {
    if (!enabled) {
      setItems([]);
      setUnreadCount(0);
      return;
    }
    void fetchAll();
    const id = window.setInterval(fetchAll, POLL_INTERVAL_MS);
    return () => window.clearInterval(id);
  }, [enabled, fetchAll]);

  // Live: prepend new notifications and bump the badge.
  const onLive = useCallback((n: AppNotification) => {
    setItems((prev) =>
      prev.some((x) => x["@id"] === n["@id"]) ? prev : [n, ...prev].slice(0, MAX_VISIBLE),
    );
    setUnreadCount((c) => c + 1);
  }, []);
  useNotificationLiveUpdates(enabled, onLive);

  const markRead = useCallback(async (n: AppNotification) => {
    setItems((prev) =>
      prev.map((x) =>
        x["@id"] === n["@id"] ? { ...x, readAt: new Date().toISOString() } : x,
      ),
    );
    setUnreadCount((c) => Math.max(0, c - 1));
    const res = await markReadRequest(n["@id"]);
    if (!res.ok) void fetchAll();
  }, [fetchAll]);

  const markAllRead = useCallback(async () => {
    const now = new Date().toISOString();
    setItems((prev) => prev.map((n) => (n.readAt ? n : { ...n, readAt: now })));
    setUnreadCount(0);
    const res = await bulkMarkReadRequest({ all: true });
    if (!res.ok) void fetchAll();
  }, [fetchAll]);

  const openTarget = useCallback(
    (n: AppNotification) => {
      setOpen(false);
      if (n.readAt === null) void markRead(n);
      if (n.targetUrl) void router.push(n.targetUrl);
    },
    [router, markRead],
  );

  const visible = useMemo(
    () => (tab === "unread" ? items.filter((n) => n.readAt === null) : items),
    [items, tab],
  );

  const grouped = useMemo(() => {
    const map = new Map<TimeGroup, AppNotification[]>();
    for (const n of visible) {
      const g = timeGroup(n.createdAt);
      const list = map.get(g) ?? [];
      list.push(n);
      map.set(g, list);
    }
    return GROUP_ORDER.filter((g) => map.has(g)).map((g) => ({
      group: g,
      rows: map.get(g) ?? [],
    }));
  }, [visible]);

  if (!enabled) return null;

  const countLabel = unreadCount > 9 ? "9+" : String(unreadCount);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          aria-label={
            unreadCount > 0
              ? `Open notifications (${unreadCount} unread)`
              : "Open notifications (no unread)"
          }
          className="relative"
          data-testid="notification-bell"
        >
          <Bell className="h-4 w-4" />
          {unreadCount > 0 && (
            <span
              className="absolute -top-0.5 -right-0.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-none text-destructive-foreground"
              data-testid="notification-bell-count"
            >
              {countLabel}
            </span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="end"
        className="w-96 p-0"
        data-testid="notification-bell-panel"
      >
        <div className="flex items-center justify-between border-b px-3 py-2">
          <div className="flex items-center gap-2">
            <p className="text-sm font-medium">Notifications</p>
            {unreadCount > 0 && (
              <span className="rounded-full bg-emerald-500/15 px-1.5 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">
                {unreadCount} new
              </span>
            )}
          </div>
          <button
            type="button"
            onClick={() => void markAllRead()}
            className="text-xs text-muted-foreground hover:text-foreground disabled:opacity-50"
            disabled={unreadCount === 0}
            data-testid="notification-mark-all-read"
          >
            Mark all read
          </button>
        </div>

        <div className="flex items-center gap-1 border-b px-3 py-1.5">
          {(["all", "unread"] as const).map((t) => (
            <button
              key={t}
              type="button"
              onClick={() => setTab(t)}
              className={cn(
                "rounded px-2 py-0.5 text-xs font-medium capitalize",
                tab === t
                  ? "bg-muted text-foreground"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              {t}
              {t === "unread" && unreadCount > 0 ? ` ${unreadCount}` : ""}
            </button>
          ))}
        </div>

        <div className="max-h-96 overflow-y-auto">
          {visible.length === 0 ? (
            <p className="px-3 py-8 text-center text-xs text-muted-foreground">
              You&apos;re all caught up.
            </p>
          ) : (
            grouped.map(({ group, rows }) => (
              <div key={group}>
                <p className="bg-muted/40 px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                  {group}
                </p>
                <ul className="divide-y">
                  {rows.map((n) => (
                    <li
                      key={n["@id"]}
                      className={cn(
                        "group flex items-start gap-2.5 px-3 py-2",
                        n.readAt === null ? "" : "opacity-60",
                      )}
                      data-testid="notification-item"
                    >
                      {n.actor ? (
                        <UserAvatar user={n.actor} size="sm" />
                      ) : (
                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-muted">
                          {(() => {
                            const Icon = metaFor(n.type).icon;
                            return <Icon className={cn("h-4 w-4", metaFor(n.type).icon_color)} />;
                          })()}
                        </span>
                      )}
                      <button
                        type="button"
                        onClick={() => openTarget(n)}
                        className="min-w-0 flex-1 text-left"
                      >
                        <div className="flex items-center gap-1.5 text-[10px] text-muted-foreground">
                          {n.contextLabel && (
                            <>
                              <span className="truncate font-mono">{n.contextLabel}</span>
                              <span aria-hidden>›</span>
                            </>
                          )}
                          <NotificationTypeBadge type={n.type} />
                        </div>
                        <p className={cn("mt-0.5 text-sm", n.readAt === null && "font-medium")}>
                          {n.title}
                        </p>
                        {n.body && (
                          <p className="line-clamp-1 text-xs text-muted-foreground">{n.body}</p>
                        )}
                        <span className="text-[10px] text-muted-foreground">
                          {formatRelative(n.createdAt)}
                        </span>
                      </button>
                      {n.readAt === null && (
                        <button
                          type="button"
                          onClick={() => void markRead(n)}
                          className="shrink-0 self-center text-[11px] text-muted-foreground opacity-0 transition-opacity hover:text-foreground group-hover:opacity-100"
                          data-testid="notification-mark-read"
                        >
                          Mark read
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            ))
          )}
        </div>

        <div className="flex items-center justify-between border-t px-3 py-2 text-xs">
          <Link
            href="/notifications"
            onClick={() => setOpen(false)}
            className="font-medium text-cyan-700 hover:underline dark:text-cyan-400"
            data-testid="notification-view-all"
          >
            View all
          </Link>
          <Link
            href="/settings"
            onClick={() => setOpen(false)}
            className="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground"
          >
            <Settings className="h-3 w-3" /> Preferences
          </Link>
        </div>
      </PopoverContent>
    </Popover>
  );
};

export default NotificationBell;
