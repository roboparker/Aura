import { useCallback, useEffect, useRef } from "react";
import { toast } from "sonner";

/** How long the user gets to undo before the delete is actually sent. */
export const UNDO_WINDOW_MS = 7000;

interface PendingDelete {
  timer: ReturnType<typeof setTimeout>;
  /** Fires the real request. Called by the timer, or early on flush. */
  commit: () => void;
}

export interface UndoableDeleteOptions<T> {
  /** Stable identity for the item — used to key the pending delete. */
  keyOf: (item: T) => string;
  /** Remove the item from local state. Runs immediately, before any request. */
  remove: (item: T) => void;
  /** Put the item back exactly where it was. Runs when the user undoes. */
  restore: (item: T) => void;
  /** Issue the actual delete. Should throw/reject on failure. */
  request: (item: T) => Promise<void>;
  /** Toast copy. */
  message: (item: T) => string;
  /** Called when the delete ultimately fails, after the item is restored. */
  onError?: (item: T, error: unknown) => void;
}

/**
 * Optimistic delete with a real undo window.
 *
 * The row disappears immediately and the network request is *deferred* for
 * `UNDO_WINDOW_MS`. Undo cancels the request outright, so undoing costs nothing
 * and needs no restore endpoint on the API.
 *
 * The subtle part is what happens when the user leaves before the window
 * closes. If we simply dropped the timer, the task would quietly come back —
 * the user watched it vanish and would have every reason to believe it was
 * gone. So any still-pending delete is *flushed* (sent immediately) on unmount
 * and on `pagehide`. Leaving the page therefore confirms the delete rather than
 * cancelling it, which matches what the user was last shown.
 */
export function useUndoableDelete<T>({
  keyOf,
  remove,
  restore,
  request,
  message,
  onError,
}: UndoableDeleteOptions<T>) {
  const pending = useRef(new Map<string, PendingDelete>());

  const flushAll = useCallback(() => {
    // Copy first: commit() deletes from the map as it goes.
    for (const entry of [...pending.current.values()]) {
      clearTimeout(entry.timer);
      entry.commit();
    }
    pending.current.clear();
  }, []);

  useEffect(() => {
    const onPageHide = () => flushAll();
    window.addEventListener("pagehide", onPageHide);
    return () => {
      window.removeEventListener("pagehide", onPageHide);
      flushAll();
    };
  }, [flushAll]);

  return useCallback(
    (item: T) => {
      const key = keyOf(item);

      // Guard against a double-click queueing two deletes for one row.
      if (pending.current.has(key)) return;

      remove(item);

      let settled = false;
      const commit = () => {
        if (settled) return;
        settled = true;
        pending.current.delete(key);
        void request(item).catch((err: unknown) => {
          // The request failed, so the item still exists server-side. Put it
          // back rather than leaving the UI lying about it.
          restore(item);
          onError?.(item, err);
        });
      };

      const timer = setTimeout(commit, UNDO_WINDOW_MS);
      pending.current.set(key, { timer, commit });

      toast(message(item), {
        duration: UNDO_WINDOW_MS,
        action: {
          label: "Undo",
          onClick: () => {
            const entry = pending.current.get(key);
            if (!entry) return; // already committed — too late to undo
            settled = true;
            clearTimeout(entry.timer);
            pending.current.delete(key);
            restore(item);
          },
        },
      });
    },
    [keyOf, remove, restore, request, message, onError],
  );
}
