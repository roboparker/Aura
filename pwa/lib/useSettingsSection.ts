import { useEffect, useState } from "react";
import type { UserPreferences } from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";

/**
 * Per-section explicit-save state for a Settings card. Holds a local draft
 * seeded from the persisted value, exposes a `dirty` flag, and only writes to
 * `PATCH /me/preferences` when `save()` is called — the buffered counterpart
 * to the auto-saving controls. Each call owns its own `usePreferencePersist`
 * instance, so the save status/error reported here are scoped to this section.
 *
 * The draft re-syncs whenever the persisted value changes (initial load, a
 * successful save, or an external update) so `dirty` stays accurate.
 */
export function useSettingsSection<T>(
  serverValue: T,
  toPatch: (draft: T) => Partial<UserPreferences>,
) {
  const { persist, saveStatus, saveError } = usePreferencePersist();
  const serverKey = JSON.stringify(serverValue);
  const [draft, setDraft] = useState<T>(serverValue);

  useEffect(() => {
    setDraft(JSON.parse(serverKey) as T);
  }, [serverKey]);

  const dirty = JSON.stringify(draft) !== serverKey;

  return {
    draft,
    setDraft,
    dirty,
    saveStatus,
    saveError,
    save: () => persist(toPatch(draft)),
    discard: () => setDraft(JSON.parse(serverKey) as T),
  };
}
