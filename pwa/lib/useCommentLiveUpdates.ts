import { useEffect } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";

/**
 * Mercure subscription for a single parent's comments topic (#228).
 * Parent can be a task or a page — the hook takes a `parentIri`
 * (e.g. `/tasks/<uuid>` or `/pages/<uuid>`) and resolves the matching
 * token endpoint + topic from it.
 *
 * Lifecycle: when `enabled` becomes true, hit the API for a
 * subscriber cookie scoped to `<parentIri>/comments`, then open an
 * EventSource on the Mercure hub. On every message, parse the JSON
 * envelope and forward it to `onEvent`. Closes the EventSource on
 * cleanup so leaving the panel collapsed doesn't keep a connection
 * open per parent.
 *
 * The hub URL is derived from the entrypoint — same-origin in dev /
 * prod (Caddy/FrankenPHP), so the EventSource picks up the
 * `mercureAuthorization` cookie automatically (path-scoped to
 * `/.well-known/mercure`).
 */
export type CommentLiveEvent =
  | {
      type: "create" | "update";
      comment: { "@id": string; [key: string]: unknown };
    }
  | { type: "delete"; id: string; parent: string };

export const useCommentLiveUpdates = (
  parentIri: string | null,
  enabled: boolean,
  onEvent: (event: CommentLiveEvent) => void,
): void => {
  useEffect(() => {
    if (!enabled || !parentIri) return;
    let cancelled = false;
    let source: EventSource | null = null;

    const open = async () => {
      try {
        const tokenRes = await fetch(
          `${ENTRYPOINT}${parentIri}/comments/mercure-token`,
          { credentials: "include" },
        );
        if (!tokenRes.ok) return;
        const { topic } = (await tokenRes.json()) as { topic: string };
        if (cancelled || !topic) return;

        const url = new URL("/.well-known/mercure", window.location.origin);
        url.searchParams.append("topic", topic);

        source = new EventSource(url.toString(), { withCredentials: true });
        source.onmessage = (event) => {
          try {
            const parsed = JSON.parse(event.data) as CommentLiveEvent;
            onEvent(parsed);
          } catch {
            // Malformed payload — drop silently rather than crash the
            // panel.
          }
        };
      } catch {
        // Network error or auth failure: live updates simply don't
        // kick in for this session.
      }
    };

    void open();

    return () => {
      cancelled = true;
      source?.close();
    };
    // `onEvent` intentionally excluded — the caller's setter is
    // stable (memoized) and re-binding on every parent render would
    // tear down the SSE connection on every keystroke elsewhere on
    // the page.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [parentIri, enabled]);
};
