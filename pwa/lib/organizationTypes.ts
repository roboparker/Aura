import type { AvatarUser } from "@/components/user/UserAvatar";

/**
 * Shapes for the `/organizations` API (`organization:read`). Shared across the
 * organizations index + detail pages so the wire contract lives in one place.
 *
 * Organizations are the GitHub-style account layer above Spaces (#billing
 * Phase 1): a Space can belong to a person (personal) or to an Organization
 * (team). Org roles are more granular than a space's admin/member.
 */

/** The five org roles, most→least privileged. `seat` = everyone but guest. */
export const ORG_ROLES = ["owner", "admin", "billing", "member", "guest"] as const;
export type OrgRole = (typeof ORG_ROLES)[number];

/** Roles that occupy a paid seat (mirrors `Organization::BILLABLE_ROLES`). */
export const ORG_SEAT_ROLES: readonly OrgRole[] = ["owner", "admin", "billing", "member"];

/** Roles allowed to manage the org (mirrors `Organization::ADMIN_ROLES`). */
export const ORG_ADMIN_ROLES: readonly OrgRole[] = ["owner", "admin"];

export const ORG_ROLE_LABELS: Record<OrgRole, string> = {
  owner: "Owner",
  admin: "Admin",
  billing: "Billing",
  member: "Member",
  guest: "Guest",
};

export const ORG_ROLE_DESCRIPTIONS: Record<OrgRole, string> = {
  owner: "Full control, including deleting the organization. Can't be removed while sole owner.",
  admin: "Manage members, spaces, and settings.",
  billing: "Manage the subscription and payment method.",
  member: "Access the organization's spaces and content. Occupies a seat.",
  guest: "Limited access. Free — doesn't count toward seats.",
};

export interface OrgMemberUser {
  id: string;
  email: string;
  givenName?: string | null;
  familyName?: string | null;
  nickname?: string | null;
  personalizedColor?: string;
  avatarUrls?: { thumb?: string; profile?: string } | null;
}

/** One row of `Organization::getMemberList()`. */
export interface OrgMember {
  id: string;
  role: OrgRole;
  joinedAt: string;
  user: OrgMemberUser;
}

export interface OrganizationSummary {
  "@id": string;
  id: string;
  email?: string | null;
}

export interface Organization {
  "@id": string;
  id: string;
  name: string;
  slug: string;
  createdBy: OrganizationSummary | string | null;
  memberList: OrgMember[];
  seatCount: number;
  createdAt: string;
  /** Set while the org is inside its deletion grace period (see deletionTypes). */
  deletedAt?: string | null;
  purgeAfter?: string | null;
}

export interface OrganizationCollection {
  member?: Organization[];
  "hydra:member"?: Organization[];
}

/** An `OrgMemberUser` always has a usable avatar color; default if absent. */
export const toOrgAvatarUser = (user: OrgMemberUser): AvatarUser => ({
  ...user,
  personalizedColor: user.personalizedColor ?? "#64748b",
});

export const isOrgAdminRole = (role: OrgRole | null | undefined): boolean =>
  !!role && ORG_ADMIN_ROLES.includes(role);
