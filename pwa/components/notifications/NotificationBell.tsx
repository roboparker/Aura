import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { Bell } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { cn } from "@/lib/utils";

interface NotificationItem {
  "@id": string;
  id: string;
  type: string;
  title: string;
  body: string | null;
  task: string | null;
  readAt: string | null;
  createdAt: string;
}

interface NotificationCollection {
  member?: NotificationItem[];
  "hydra:member"?: NotificationItem[];
  totalItems?: number;
}

const POLL_INTERVAL_MS = 30_000;
// Cap the dropdown so a long-time user with stale unread items doesn't
// render hundreds of rows in the chrome.
const MAX_VISIBLE = 8;

const collectionMembers = (data: NotificationCollection): NotificationItem[] =>
  data.member ?? data["hydra:member"] ?? [];

const formatRelative = (iso: string): string => {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diffMs = Date.now() - ts;
  const diffMin = Math.round(diffMs / 60_000);
  if (diffMin < 1) return "just now";
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHour = Math.round(diffMin / 60);
  if (diffHour < 24) return `${diffHour}h ago`;
  const diffDay = Math.round(diffHour / 24);
  return `${diffDay}d ago`;
};

interface NotificationBellProps {
  /** Hides the bell when the user isn't logged in. Polling also pauses,
   *  so we don't spam the API with 401s on the public landing page. */
  enabled: boolean;
}

const NotificationBell = ({ enabled }: NotificationBellProps) => {
  const [unread, setUnread] = useState<NotificationItem[]>([]);
  const [open, setOpen] = useState(false);
  // Used so a quick mark-read doesn't get stomped by an in-flight poll
  // returning the stale "still unread" snapshot.
  const lastFetchedAt = useRef(0);

  const fetchUnread = useCallback(async () => {
    if (!enabled) return;
    try {
      const res = await fetch(
        `${ENTRYPOINT}/notifications?exists[readAt]=false&itemsPerPage=${MAX_VISIBLE}`,
        {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        },
      );
      if (!res.ok) return;
      const data: NotificationCollection = await res.json();
      lastFetchedAt.current = Date.now();
      setUnread(collectionMembers(data));
    } catch {
      // Polling is a chrome convenience — surface nothing to the user
      // if it fails; the next tick will retry.
    }
  }, [enabled]);

  useEffect(() => {
    if (!enabled) {
      setUnread([]);
      return;
    }
    fetchUnread();
    const id = window.setInterval(fetchUnread, POLL_INTERVAL_MS);
    return () => window.clearInterval(id);
  }, [enabled, fetchUnread]);

  // Refetch on focus so the count stays fresh after a long idle without
  // forcing the polling interval down.
  useEffect(() => {
    if (!enabled) return;
    const onVisible = () => {
      if (document.visibilityState === "visible") fetchUnread();
    };
    document.addEventListener("visibilitychange", onVisible);
    return () => document.removeEventListener("visibilitychange", onVisible);
  }, [enabled, fetchUnread]);

  const markRead = useCallback(
    async (notification: NotificationItem) => {
      // Optimistic removal — drop from the dropdown immediately.
      setUnread((current) =>
        current.filter((n) => n["@id"] !== notification["@id"]),
      );
      try {
        await fetch(`${ENTRYPOINT}${notification["@id"]}`, {
          method: "PATCH",
          credentials: "include",
          headers: { "Content-Type": "application/merge-patch+json" },
          body: JSON.stringify({ readAt: new Date().toISOString() }),
        });
      } catch {
        // Re-poll to recover from a failed mark-read so the count
        // reflects reality on the next tick.
        fetchUnread();
      }
    },
    [fetchUnread],
  );

  if (!enabled) return null;

  const count = unread.length;
  const countLabel = count > 9 ? "9+" : String(count);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          aria-label={
            count > 0
              ? `Open notifications (${count} unread)`
              : "Open notifications (no unread)"
          }
          className="relative"
          data-testid="notification-bell"
        >
          <Bell className="h-4 w-4" />
          {count > 0 && (
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
        className="w-80 p-0"
        data-testid="notification-bell-panel"
      >
        <div className="px-3 py-2 border-b">
          <p className="text-sm font-medium">Notifications</p>
          <p className="text-xs text-muted-foreground">
            {count === 0
              ? "You're all caught up."
              : `${count} unread`}
          </p>
        </div>
        <ul className="max-h-80 overflow-y-auto divide-y">
          {unread.length === 0 ? (
            <li className="px-3 py-6 text-center text-xs text-muted-foreground">
              No new reminders.
            </li>
          ) : (
            unread.map((n) => (
              <li
                key={n["@id"]}
                className="px-3 py-2 text-sm space-y-1"
                data-testid="notification-item"
              >
                <p className="font-medium">{n.title}</p>
                {n.body && (
                  <p className="text-xs text-muted-foreground">{n.body}</p>
                )}
                <div className="flex items-center justify-between text-xs">
                  <span className="text-muted-foreground">
                    {formatRelative(n.createdAt)}
                  </span>
                  <div className="flex items-center gap-2">
                    {n.task && (
                      <Link
                        href="/tasks"
                        className={cn(
                          "underline-offset-4 hover:underline",
                          "text-cyan-700",
                        )}
                        onClick={() => setOpen(false)}
                      >
                        View tasks
                      </Link>
                    )}
                    <button
                      type="button"
                      className="underline-offset-4 hover:underline text-muted-foreground"
                      onClick={() => markRead(n)}
                      data-testid="notification-mark-read"
                    >
                      Mark read
                    </button>
                  </div>
                </div>
              </li>
            ))
          )}
        </ul>
      </PopoverContent>
    </Popover>
  );
};

export default NotificationBell;
