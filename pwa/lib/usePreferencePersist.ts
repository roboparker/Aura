import { useCallback, useEffect, useRef, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { useAuth, type UserPreferences } from "@/contexts/AuthContext";

export type SaveStatus = "idle" | "saving" | "saved" | "error";

/**
 * Shared optimistic save for `PATCH /me/preferences`, used by the Settings
 * panels (theme, timezone, notifications). Mirrors the original inline logic
 * from the old single settings page: optimistic local merge, a monotonic
 * request id so a slow-then-fast pair can't clobber the newer state, and a
 * transient "saved" pill that auto-clears.
 *
 * Note: the server merges preference keys shallowly, so nested-object keys
 * (added in later phases) must be sent whole.
 */
export const usePreferencePersist = () => {
  const { user, updateUserLocally } = useAuth();
  const [saveStatus, setSaveStatus] = useState<SaveStatus>("idle");
  const [saveError, setSaveError] = useState<string | null>(null);
  const pendingRequestId = useRef(0);
  const savedToastTimer = useRef<number | null>(null);

  useEffect(
    () => () => {
      if (savedToastTimer.current !== null) {
        window.clearTimeout(savedToastTimer.current);
      }
    },
    [],
  );

  const persist = useCallback(
    async (patch: Partial<UserPreferences>) => {
      if (!user) return;
      const previous = user.preferences;
      const next = { ...previous, ...patch };
      updateUserLocally({ preferences: next });
      setSaveStatus("saving");
      setSaveError(null);

      const requestId = ++pendingRequestId.current;
      try {
        const res = await fetch(`${ENTRYPOINT}/me/preferences`, {
          method: "PATCH",
          credentials: "include",
          headers: { "Content-Type": "application/merge-patch+json" },
          body: JSON.stringify(patch),
        });
        if (!res.ok) {
          const data = await res.json().catch(() => ({}));
          throw new Error(data.error || "Failed to save preferences.");
        }
        if (requestId !== pendingRequestId.current) return;
        const body = (await res.json()) as UserPreferences;
        updateUserLocally({ preferences: body });
        setSaveStatus("saved");
        if (savedToastTimer.current !== null) {
          window.clearTimeout(savedToastTimer.current);
        }
        savedToastTimer.current = window.setTimeout(
          () => setSaveStatus("idle"),
          1500,
        );
      } catch (err) {
        if (requestId !== pendingRequestId.current) return;
        updateUserLocally({ preferences: previous });
        setSaveStatus("error");
        setSaveError(
          err instanceof Error ? err.message : "Failed to save preferences.",
        );
      }
    },
    [user, updateUserLocally],
  );

  return { persist, saveStatus, saveError };
};
