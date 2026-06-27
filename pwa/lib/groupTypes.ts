import type { AvatarUser } from "@/components/user/UserAvatar";

/**
 * Shapes for the `/groups` API (`group:read`). Shared across the groups
 * index, detail, and create pages so the wire contract lives in one
 * place.
 */
export interface GroupUser {
  "@id": string;
  id: string;
  email: string;
  givenName?: string | null;
  familyName?: string | null;
  nickname?: string | null;
  personalizedColor?: string;
  avatarUrls?: { thumb?: string; profile?: string } | null;
}

export interface GroupMembership {
  "@id": string;
  id: string;
  user: GroupUser;
  joinedAt: string;
}

/** Lean summary emitted by `UserGroup::getSpaceSummary()` (the owning space). */
export interface GroupSpaceSummary {
  id: string;
  name: string;
  color: string | null;
  isPersonal: boolean;
}

export interface Group {
  "@id": string;
  id: string;
  title: string;
  slug: string;
  description: string | null;
  color: string | null;
  spaceSummary: GroupSpaceSummary | null;
  memberships: GroupMembership[];
  createdOn: string;
  updatedAt: string;
}

export interface GroupCollection {
  member?: Group[];
  "hydra:member"?: Group[];
}

/** Pending email invite as returned by `GET /groups/{id}/invites`. */
export interface GroupPendingInvite {
  id: string;
  email: string;
  invitedBy: string;
  createdAt: string;
  expiresAt: string;
}

/** A `GroupUser` always has a usable avatar color; default if absent. */
export const toGroupAvatarUser = (user: GroupUser): AvatarUser => ({
  ...user,
  personalizedColor: user.personalizedColor ?? "#64748b",
});
