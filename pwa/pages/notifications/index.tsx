import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Archive, Bell, Check, Settings, X } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { useNotifications } from "@/lib/useNotifications";
import { useNotificationLiveUpdates } from "@/lib/useNotificationLiveUpdates";
import { timeGroup, type TimeGroup } from "@/lib/relativeTime";
import {
  NOTIFICATION_TABS,
  type AppNotification,
} from "@/lib/notificationTypes";
import NotificationRow from "@/components/notifications/NotificationRow";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";

const GROUP_ORDER: TimeGroup[] = ["Today", "This week", "Earlier"];

const NotificationsPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [tabKey, setTabKey] = useState("all");
  const [archived, setArchived] = useState(false);
  const [selected, setSelected] = useState<Set<string>>(new Set());

  const types = useMemo(
    () => NOTIFICATION_TABS.find((t) => t.key === tabKey)?.types ?? null,
    [tabKey],
  );

  const {
    items,
    isLoading,
    prepend,
    markRead,
    archive,
    markAllRead,
    bulkMarkRead,
    bulkArchive,
  } = useNotifications({ enabled: isAuthenticated, types, archived });

  useNotificationLiveUpdates(isAuthenticated, prepend);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  // Drop selections that left the current view (filter/tab change, archive).
  useEffect(() => {
    setSelected((prev) => {
      const live = new Set(items.map((n) => n.id));
      const next = new Set([...prev].filter((id) => live.has(id)));
      return next.size === prev.size ? prev : next;
    });
  }, [items]);

  const open = useCallback(
    (n: AppNotification) => {
      if (n.readAt === null) void markRead(n);
      if (n.targetUrl) void router.push(n.targetUrl);
    },
    [markRead, router],
  );

  const toggleSelect = useCallback((id: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }, []);

  const unreadCount = useMemo(
    () => items.filter((n) => n.readAt === null).length,
    [items],
  );
  const contextCount = useMemo(
    () => new Set(items.map((n) => n.contextLabel).filter(Boolean)).size,
    [items],
  );

  const grouped = useMemo(() => {
    const map = new Map<TimeGroup, AppNotification[]>();
    for (const n of items) {
      const g = timeGroup(n.createdAt);
      const list = map.get(g) ?? [];
      list.push(n);
      map.set(g, list);
    }
    return GROUP_ORDER.filter((g) => map.has(g)).map((g) => ({
      group: g,
      rows: map.get(g) ?? [],
    }));
  }, [items]);

  if (authLoading || !user) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  const selectedIds = [...selected];

  return (
    <>
      <Head>
        <title>Notifications - Madori</title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="mx-auto max-w-3xl space-y-5 px-4 py-8">
          <header className="flex flex-wrap items-start justify-between gap-3">
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <Bell className="h-6 w-6 text-cyan-600 dark:text-cyan-400" />
                <h1 className="text-2xl font-bold">Notifications</h1>
              </div>
              <p className="text-sm text-muted-foreground">
                {unreadCount} unread
                {contextCount > 0 ? ` · across ${contextCount} ${contextCount === 1 ? "place" : "places"}` : ""}
              </p>
            </div>
            <div className="flex items-center gap-2">
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() => void markAllRead()}
                disabled={unreadCount === 0}
                data-testid="notifications-mark-all-read"
              >
                <Check className="mr-1 h-3.5 w-3.5" /> Mark all read
              </Button>
              <Button asChild size="sm" variant="outline">
                <Link href="/settings/notifications">
                  <Settings className="mr-1 h-3.5 w-3.5" /> Preferences
                </Link>
              </Button>
            </div>
          </header>

          {/* Tabs + archived toggle */}
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex flex-wrap gap-1" role="tablist" aria-label="Filter notifications">
              {NOTIFICATION_TABS.map((t) => (
                <button
                  key={t.key}
                  type="button"
                  role="tab"
                  aria-selected={tabKey === t.key}
                  onClick={() => setTabKey(t.key)}
                  className={cn(
                    "rounded-full border px-3 py-1 text-xs font-medium transition-colors",
                    tabKey === t.key
                      ? "border-primary bg-primary text-primary-foreground"
                      : "border-input bg-background text-muted-foreground hover:text-foreground",
                  )}
                >
                  {t.label}
                </button>
              ))}
            </div>
            <button
              type="button"
              onClick={() => setArchived((v) => !v)}
              className={cn(
                "inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-medium transition-colors",
                archived
                  ? "border-primary bg-primary text-primary-foreground"
                  : "border-input bg-background text-muted-foreground hover:text-foreground",
              )}
              data-testid="notifications-archived-toggle"
            >
              <Archive className="h-3.5 w-3.5" />
              {archived ? "Viewing archived" : "View archived"}
            </button>
          </div>

          {/* Bulk action bar */}
          {selectedIds.length > 0 && (
            <div
              className="flex items-center gap-2 rounded-lg border border-border bg-background px-3 py-2 text-sm"
              data-testid="notifications-bulk-bar"
            >
              <span className="font-medium">{selectedIds.length} selected</span>
              <span className="flex-1" />
              {!archived && (
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    void bulkMarkRead(selectedIds);
                    setSelected(new Set());
                  }}
                >
                  <Check className="mr-1 h-3.5 w-3.5" /> Mark read
                </Button>
              )}
              {!archived && (
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    void bulkArchive(selectedIds);
                    setSelected(new Set());
                  }}
                >
                  <Archive className="mr-1 h-3.5 w-3.5" /> Archive
                </Button>
              )}
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() => setSelected(new Set())}
              >
                <X className="mr-1 h-3.5 w-3.5" /> Clear
              </Button>
            </div>
          )}

          {/* List */}
          {isLoading && items.length === 0 ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : items.length === 0 ? (
            <Card>
              <CardContent className="flex flex-col items-center px-6 py-12 text-center">
                <span className="inline-flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                  <Bell className="h-6 w-6 text-muted-foreground" />
                </span>
                <h3 className="mt-4 text-lg font-semibold">You&apos;re all caught up</h3>
                <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                  {archived
                    ? "Nothing archived yet."
                    : "New mentions, assignments, replies, and status updates will land here."}
                </p>
              </CardContent>
            </Card>
          ) : (
            <div className="overflow-hidden rounded-lg border border-border bg-background">
              {grouped.map(({ group, rows }) => (
                <div key={group}>
                  <p className="border-b border-border bg-muted/40 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {group}
                  </p>
                  <ul className="divide-y divide-border">
                    {rows.map((n) => (
                      <NotificationRow
                        key={n["@id"]}
                        notification={n}
                        selectable
                        selected={selected.has(n.id)}
                        onToggleSelect={() => toggleSelect(n.id)}
                        onMarkRead={archived ? undefined : markRead}
                        onArchive={archived ? undefined : archive}
                        onOpen={open}
                      />
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          )}
        </div>
      </main>
    </>
  );
};

export default NotificationsPage;
