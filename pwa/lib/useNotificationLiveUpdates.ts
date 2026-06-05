import { useEffect } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import type { AppNotification } from "@/lib/notificationTypes";

/**
 * Mercure subscription to the current user's private notifications topic
 * (`/users/{id}/notifications`). Mirrors `useCommentLiveUpdates`: fetch a
 * subscriber cookie from `/notifications/mercure-token`, open an
 * EventSource against the hub, and forward each `create` event to
 * `onCreate`. Lets the bell + inbox update live instead of polling.
 *
 * The token endpoint scopes the cookie to the caller's own topic, so a
 * user can only ever receive their own notifications.
 */
type NotificationLiveEvent = {
  type: "create";
  notification: AppNotification;
};

export const useNotificationLiveUpdates = (
  enabled: boolean,
  onCreate: (notification: AppNotification) => void,
): void => {
  useEffect(() => {
    if (!enabled) return;
    let cancelled = false;
    let source: EventSource | null = null;

    const open = async () => {
      try {
        const tokenRes = await fetch(`${ENTRYPOINT}/notifications/mercure-token`, {
          credentials: "include",
        });
        if (!tokenRes.ok) return;
        const { topic } = (await tokenRes.json()) as { topic: string };
        if (cancelled || !topic) return;

        const url = new URL("/.well-known/mercure", window.location.origin);
        url.searchParams.append("topic", topic);

        source = new EventSource(url.toString(), { withCredentials: true });
        source.onmessage = (event) => {
          try {
            const parsed = JSON.parse(event.data) as NotificationLiveEvent;
            if (parsed.type === "create" && parsed.notification) {
              onCreate(parsed.notification);
            }
          } catch {
            // Malformed payload — drop silently.
          }
        };
      } catch {
        // Auth/network failure: live updates simply don't kick in.
      }
    };

    void open();

    return () => {
      cancelled = true;
      source?.close();
    };
    // `onCreate` intentionally excluded — callers pass a stable setter, and
    // re-binding would tear down the SSE connection on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled]);
};
