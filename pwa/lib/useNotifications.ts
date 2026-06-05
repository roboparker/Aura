import { useCallback, useEffect, useRef, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import {
  collectionMembers,
  type AppNotification,
  type NotificationCollection,
  type NotificationType,
} from "@/lib/notificationTypes";

// --- Shared request helpers (used by both the bell and the inbox page) ---

export const markReadRequest = (iri: string): Promise<Response> =>
  fetch(`${ENTRYPOINT}${iri}`, {
    method: "PATCH",
    credentials: "include",
    headers: { "Content-Type": "application/merge-patch+json" },
    body: JSON.stringify({ readAt: new Date().toISOString() }),
  });

export const archiveRequest = (iri: string): Promise<Response> =>
  fetch(`${ENTRYPOINT}${iri}`, {
    method: "PATCH",
    credentials: "include",
    headers: { "Content-Type": "application/merge-patch+json" },
    body: JSON.stringify({ archivedAt: new Date().toISOString() }),
  });

export const bulkMarkReadRequest = (
  body: { ids: string[] } | { all: true },
): Promise<Response> =>
  fetch(`${ENTRYPOINT}/notifications/mark-read`, {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });

export const bulkArchiveRequest = (ids: string[]): Promise<Response> =>
  fetch(`${ENTRYPOINT}/notifications/archive`, {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ids }),
  });

interface Args {
  enabled: boolean;
  types: NotificationType[] | null;
  archived: boolean;
}

interface Result {
  items: AppNotification[];
  isLoading: boolean;
  refetch: () => void;
  prepend: (n: AppNotification) => void;
  markRead: (n: AppNotification) => Promise<void>;
  archive: (n: AppNotification) => Promise<void>;
  markAllRead: () => Promise<void>;
  bulkMarkRead: (ids: string[]) => Promise<void>;
  bulkArchive: (ids: string[]) => Promise<void>;
}

/**
 * Loads + mutates the inbox list for the `/notifications` page. Filters
 * by tab (`types`) and the active/archived split; folds live Mercure
 * events in via `prepend`. Mutations are optimistic with a refetch
 * fallback on error.
 */
export const useNotifications = ({ enabled, types, archived }: Args): Result => {
  const [items, setItems] = useState<AppNotification[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const reqId = useRef(0);

  const load = useCallback(async () => {
    if (!enabled) return;
    const mine = ++reqId.current;
    setIsLoading(true);
    try {
      const params = new URLSearchParams();
      if (types) types.forEach((t) => params.append("type[]", t));
      params.set("exists[archivedAt]", archived ? "true" : "false");
      params.set("itemsPerPage", "50");
      const res = await fetch(`${ENTRYPOINT}/notifications?${params.toString()}`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok || mine !== reqId.current) return;
      const data: NotificationCollection = await res.json();
      if (mine === reqId.current) setItems(collectionMembers(data));
    } catch {
      // Leave the last good list in place.
    } finally {
      if (mine === reqId.current) setIsLoading(false);
    }
  }, [enabled, types, archived]);

  useEffect(() => {
    if (!enabled) {
      setItems([]);
      return;
    }
    void load();
  }, [enabled, load]);

  const prepend = useCallback(
    (n: AppNotification) => {
      // Only fold into the current view if it belongs here.
      if (archived) return;
      if (types && !types.includes(n.type as NotificationType)) return;
      setItems((prev) =>
        prev.some((x) => x["@id"] === n["@id"]) ? prev : [n, ...prev],
      );
    },
    [archived, types],
  );

  const patchLocal = useCallback(
    (iri: string, patch: Partial<AppNotification>) => {
      setItems((prev) =>
        prev.map((n) => (n["@id"] === iri ? { ...n, ...patch } : n)),
      );
    },
    [],
  );

  const dropLocal = useCallback((iri: string) => {
    setItems((prev) => prev.filter((n) => n["@id"] !== iri));
  }, []);

  const markRead = useCallback(
    async (n: AppNotification) => {
      patchLocal(n["@id"], { readAt: new Date().toISOString() });
      const res = await markReadRequest(n["@id"]);
      if (!res.ok) void load();
    },
    [patchLocal, load],
  );

  const archive = useCallback(
    async (n: AppNotification) => {
      dropLocal(n["@id"]);
      const res = await archiveRequest(n["@id"]);
      if (!res.ok) void load();
    },
    [dropLocal, load],
  );

  const markAllRead = useCallback(async () => {
    const now = new Date().toISOString();
    setItems((prev) => prev.map((n) => (n.readAt ? n : { ...n, readAt: now })));
    const res = await bulkMarkReadRequest({ all: true });
    if (!res.ok) void load();
  }, [load]);

  const bulkMarkRead = useCallback(
    async (ids: string[]) => {
      const now = new Date().toISOString();
      setItems((prev) =>
        prev.map((n) => (ids.includes(n.id) ? { ...n, readAt: now } : n)),
      );
      const res = await bulkMarkReadRequest({ ids });
      if (!res.ok) void load();
    },
    [load],
  );

  const bulkArchive = useCallback(
    async (ids: string[]) => {
      setItems((prev) => prev.filter((n) => !ids.includes(n.id)));
      const res = await bulkArchiveRequest(ids);
      if (!res.ok) void load();
    },
    [load],
  );

  return {
    items,
    isLoading,
    refetch: load,
    prepend,
    markRead,
    archive,
    markAllRead,
    bulkMarkRead,
    bulkArchive,
  };
};
