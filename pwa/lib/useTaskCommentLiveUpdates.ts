import { useEffect } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";

/**
 * Mercure subscription for a single task's comments topic.
 *
 * Lifecycle: when `enabled` becomes true, hit the API for a subscriber
 * cookie scoped to `/tasks/{id}/comments`, then open an EventSource on
 * the Mercure hub. On every message, parse the JSON envelope and
 * forward it to `onEvent`. Closes the EventSource on cleanup so leaving
 * the panel collapsed doesn't keep a connection open per task.
 *
 * The hub URL is derived from the entrypoint — same-origin in dev /
 * prod (Caddy/FrankenPHP), so the EventSource picks up the
 * `mercureAuthorization` cookie automatically (path-scoped to
 * `/.well-known/mercure`).
 */
export type CommentLiveEvent =
  | { type: "create" | "update"; comment: { "@id": string; [key: string]: unknown } }
  | { type: "delete"; id: string; task: string };

export const useTaskCommentLiveUpdates = (
  taskId: string | null,
  enabled: boolean,
  onEvent: (event: CommentLiveEvent) => void,
): void => {
  useEffect(() => {
    if (!enabled || !taskId) return;
    let cancelled = false;
    let source: EventSource | null = null;

    const open = async () => {
      try {
        // The token endpoint sets the mercureAuthorization cookie scoped
        // to the topic; this fetch carries the session cookie via
        // credentials: 'include' and writes back the subscriber cookie.
        const tokenRes = await fetch(
          `${ENTRYPOINT}/tasks/${encodeURIComponent(taskId)}/comments/mercure-token`,
          { credentials: "include" },
        );
        if (!tokenRes.ok) return;
        const { topic } = (await tokenRes.json()) as { topic: string };
        if (cancelled || !topic) return;

        // Mercure hub URL — same-origin via Caddy on /.well-known/mercure.
        // Using a relative path keeps env-handling out of the client.
        const url = new URL("/.well-known/mercure", window.location.origin);
        url.searchParams.append("topic", topic);

        source = new EventSource(url.toString(), { withCredentials: true });
        source.onmessage = (event) => {
          try {
            const parsed = JSON.parse(event.data) as CommentLiveEvent;
            onEvent(parsed);
          } catch {
            // Malformed payload — drop silently rather than crash the panel.
          }
        };
      } catch {
        // Network error or auth failure: live updates simply don't kick
        // in for this session. Stale state until the user reloads is
        // a tolerable degradation.
      }
    };

    void open();

    return () => {
      cancelled = true;
      source?.close();
    };
    // `onEvent` intentionally excluded — the caller's setter is stable
    // (memoized) and re-binding on every parent render would tear down
    // the SSE connection on every keystroke elsewhere on the page.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [taskId, enabled]);
};
