import {
  ReactNode,
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";
import { ENTRYPOINT } from "../config/entrypoint";
import { useAuth } from "./AuthContext";

export interface SpaceMember {
  "@id": string;
  id: string;
  email: string;
  givenName?: string;
  familyName?: string;
  nickname?: string | null;
  personalizedColor?: string;
  avatarUrls?: { thumb?: string; profile?: string } | null;
}

export interface SpaceMembershipRow {
  "@id": string;
  id: string;
  user: SpaceMember;
  role: "admin" | "member";
}

export interface SpaceGroupMembershipRow {
  "@id": string;
  id: string;
  userGroup: { "@id": string; id: string; title?: string };
  role: "admin" | "member";
}

export interface SpaceAttachment {
  "@id": string;
  id: string;
  originalName: string;
  mimeType: string;
  byteSize: number;
  variantUrls: { original?: string };
  downloadUrl?: string | null;
  createdOn?: string;
  owner?: {
    email: string;
    givenName?: string | null;
    familyName?: string | null;
  } | null;
}

export interface Space {
  "@id": string;
  id: string;
  name: string;
  description: string | null;
  isPersonal: boolean;
  visibility: "private" | "shared";
  /** Optional override for the avatar tile color. `null` means
   *  "inherit from the creator's `personalizedColor`" — the PWA
   *  resolves this in {@link resolveSpaceColor}. */
  color: string | null;
  createdAt: string;
  updatedAt: string;
  createdBy: {
    "@id": string;
    id: string;
    email: string;
    personalizedColor?: string;
  } | null;
  userMemberships: SpaceMembershipRow[];
  groupMemberships: SpaceGroupMembershipRow[];
  /** EXTRA_LAZY-counted on the API (`Space::getProjectsCount`). */
  projectsCount: number;
  /** EXTRA_LAZY-counted on the API (`Space::getPagesCount`). */
  pagesCount: number;
  /** Shared files attached at the space level. Present on the detail
   *  endpoint; may be undefined on the list endpoint when the
   *  collection isn't expanded. */
  attachments?: SpaceAttachment[];
}

interface SpaceCollection {
  member?: Space[];
  "hydra:member"?: Space[];
}

interface ActiveSpaceContextType {
  /** Every space the current user belongs to (direct or via group). */
  spaces: Space[];
  /** The non-deletable "Private" space attached to the user, or null while loading. */
  personalSpace: Space | null;
  /** The currently selected space — drives listing scopes. Null while loading. */
  activeSpace: Space | null;
  /** Switch the active space. Persists the choice in localStorage. */
  setActiveSpace: (space: Space) => void;
  /** Reload the user's space list — call after creating, deleting, or being invited to a space. */
  refresh: () => Promise<void>;
  /** Convenience: is the current user a direct admin in the active space? */
  isActiveSpaceAdmin: boolean;
  isLoading: boolean;
  error: string | null;
}

const ActiveSpaceContext = createContext<ActiveSpaceContextType | undefined>(
  undefined,
);

// Per-user storage key — `aura.activeSpaceId.{userId}` — so two
// accounts alternating on one browser never inherit each other's
// selection. The legacy un-scoped key is dropped on sight (see below).
const STORAGE_PREFIX = "aura.activeSpaceId";
const LEGACY_STORAGE_KEY = "aura.activeSpaceId";
const storageKeyFor = (userId: string) => `${STORAGE_PREFIX}.${userId}`;

// sessionStorage flag set by the sign-in flow (see markActiveSpaceReset)
// so the next active-space resolution starts in the personal space
// rather than restoring whatever was last selected on this browser.
const RESET_FLAG_KEY = "aura.activeSpaceReset";

/**
 * Called by the sign-in flow on a successful login so the post-login
 * landing starts in the user's Private space instead of restoring the
 * previous browser-local selection. Consumed once by ActiveSpaceContext
 * when the authenticated user resolves; mid-session reloads (no flag)
 * keep the currently selected space.
 */
export function markActiveSpaceReset() {
  if (typeof window === "undefined") return;
  try {
    window.sessionStorage.setItem(RESET_FLAG_KEY, "1");
  } catch {
    // Private-mode / storage-disabled browsers: the worst case is that
    // a fresh sign-in restores the last space instead of Private, which
    // is the pre-#405 behavior — acceptable, not worth surfacing.
  }
}

/**
 * Loads the user's space list from `GET /spaces` (already scoped to
 * the caller by SpaceAccessExtension) and tracks the active space —
 * the one whose content the listings should show.
 *
 * The active-space choice is persisted in localStorage under a
 * per-user key (`aura.activeSpaceId.{userId}`) so it sticks across
 * reloads. On a fresh sign-in (flagged by `markActiveSpaceReset`), on
 * first load, or when the previous selection is no longer accessible
 * (left the space, deleted it, etc.), we fall back to the personal
 * "Private" space, which is always present.
 *
 * Mounted INSIDE AuthProvider so it can react to login/logout: spaces
 * are re-fetched whenever the authenticated user changes, and cleared
 * on logout.
 */
export function ActiveSpaceProvider({ children }: { children: ReactNode }) {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();

  const [spaces, setSpaces] = useState<Space[]>([]);
  const [activeSpaceId, setActiveSpaceId] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Resolve the persisted choice whenever the authenticated user
  // settles. We can't read localStorage during the initial render in
  // Next.js (no `window` server-side), so the active-space stays null
  // on first paint and settles after this effect runs.
  //
  // On a fresh sign-in (RESET_FLAG_KEY set by markActiveSpaceReset) we
  // discard any stored choice and start in the personal space; on a
  // plain reload we restore the per-user selection so it survives.
  useEffect(() => {
    if (typeof window === "undefined") return;
    // Drop the legacy un-scoped key so a selection can never bleed
    // across accounts on a shared browser.
    window.localStorage.removeItem(LEGACY_STORAGE_KEY);

    if (!user?.id) {
      setActiveSpaceId(null);
      return;
    }

    if (window.sessionStorage.getItem(RESET_FLAG_KEY)) {
      window.sessionStorage.removeItem(RESET_FLAG_KEY);
      window.localStorage.removeItem(storageKeyFor(user.id));
      setActiveSpaceId(null);
      return;
    }

    setActiveSpaceId(window.localStorage.getItem(storageKeyFor(user.id)));
  }, [user?.id]);

  const fetchSpaces = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/spaces?itemsPerPage=100`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) throw new Error("Failed to load spaces.");
      const data: SpaceCollection = await res.json();
      const list = data.member ?? data["hydra:member"] ?? [];
      setSpaces(list);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load spaces.");
      setSpaces([]);
    } finally {
      setIsLoading(false);
    }
  }, []);

  // Re-fetch whenever the authenticated user changes; clear on logout.
  useEffect(() => {
    if (authLoading) return;
    if (!isAuthenticated) {
      setSpaces([]);
      setActiveSpaceId(null);
      return;
    }
    void fetchSpaces();
    // user.id is the dependency — switching accounts in the same tab
    // (impersonation, sign-out + sign-in) gives a fresh list.
  }, [authLoading, isAuthenticated, user?.id, fetchSpaces]);

  const personalSpace = spaces.find((s) => s.isPersonal) ?? null;

  // Resolve the active space: persisted choice if the user still has
  // access, otherwise the personal space. Never returns a row the
  // user isn't a member of.
  const activeSpace =
    spaces.find((s) => s.id === activeSpaceId) ??
    personalSpace ??
    spaces[0] ??
    null;

  const setActiveSpace = useCallback(
    (space: Space) => {
      setActiveSpaceId(space.id);
      if (typeof window !== "undefined" && user?.id) {
        window.localStorage.setItem(storageKeyFor(user.id), space.id);
      }
    },
    [user],
  );

  const isActiveSpaceAdmin = !!(
    activeSpace &&
    user &&
    activeSpace.userMemberships.some(
      (m) => m.user.id === user.id && m.role === "admin",
    )
  );

  return (
    <ActiveSpaceContext.Provider
      value={{
        spaces,
        personalSpace,
        activeSpace,
        setActiveSpace,
        refresh: fetchSpaces,
        isActiveSpaceAdmin,
        isLoading,
        error,
      }}
    >
      {children}
    </ActiveSpaceContext.Provider>
  );
}

export function useActiveSpace(): ActiveSpaceContextType {
  const ctx = useContext(ActiveSpaceContext);
  if (!ctx) {
    throw new Error("useActiveSpace must be used within an ActiveSpaceProvider");
  }
  return ctx;
}
