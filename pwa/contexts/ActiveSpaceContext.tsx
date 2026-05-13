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
}

export interface Space {
  "@id": string;
  id: string;
  name: string;
  description: string | null;
  isPersonal: boolean;
  createdAt: string;
  createdBy: { "@id": string; id: string; email: string } | null;
  userMemberships: SpaceMembershipRow[];
  groupMemberships: SpaceGroupMembershipRow[];
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

const STORAGE_KEY = "aura.activeSpaceId";

/**
 * Loads the user's space list from `GET /spaces` (already scoped to
 * the caller by SpaceAccessExtension) and tracks the active space —
 * the one whose content the listings should show.
 *
 * The active-space choice is persisted in localStorage under
 * `aura.activeSpaceId` so it sticks across reloads. On first load
 * (or when the previous selection is no longer accessible — left the
 * space, deleted it, etc.) we fall back to the personal "Private"
 * space, which is always present.
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

  // Restore the persisted choice once on mount. We can't read
  // localStorage during the initial render in Next.js (no `window`
  // server-side), so the active-space stays null on first paint and
  // settles after this effect runs.
  useEffect(() => {
    if (typeof window === "undefined") return;
    const stored = window.localStorage.getItem(STORAGE_KEY);
    if (stored) setActiveSpaceId(stored);
  }, []);

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

  const setActiveSpace = useCallback((space: Space) => {
    setActiveSpaceId(space.id);
    if (typeof window !== "undefined") {
      window.localStorage.setItem(STORAGE_KEY, space.id);
    }
  }, []);

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
