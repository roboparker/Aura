// Shared types + helpers for the admin segments UI. A segment is an
// instance-level cohort (A/B test, beta, rollout) that surfaces as the
// synthetic role ROLE_SEGMENT_<KEY>; see api/src/Entity/Segment.php.

export type SegmentPurpose = "beta" | "ab_test" | "rollout";

export type SegmentMembershipMode = "include" | "exclude";

export interface SegmentMemberUser {
  id: string;
  email: string;
  givenName: string;
  familyName: string;
  personalizedColor: string;
}

export interface SegmentMembership {
  id: string;
  user: SegmentMemberUser | null;
  mode: SegmentMembershipMode;
  joinedAt: string;
}

export interface Segment {
  "@id"?: string;
  id: string;
  key: string;
  role: string;
  name: string;
  description: string | null;
  purpose: SegmentPurpose;
  enabled: boolean;
  rolloutPercentage: number | null;
  includedRoles: string[];
  memberships: SegmentMembership[];
  createdOn: string;
  updatedAt: string;
}

export interface SegmentReach {
  total: number;
  manualIncludes: number;
  manualExcludes: number;
  byRole: number;
  byRollout: number;
}

export interface SegmentPreview {
  enabled: boolean;
  rolloutPercentage: number | null;
  includedRoles: string[];
  reach: SegmentReach;
}

export const PURPOSE_LABELS: Record<SegmentPurpose, string> = {
  beta: "Beta",
  ab_test: "A/B test",
  rollout: "Rollout",
};

export const PURPOSE_OPTIONS: SegmentPurpose[] = ["beta", "ab_test", "rollout"];

// Roles offered as quick toggles in the role-rule editor. Admins can still
// type any other ROLE_* value; these are just the common ones.
export const COMMON_ROLES = ["ROLE_USER", "ROLE_ADMIN"];

export const memberFullName = (u: SegmentMemberUser): string =>
  `${u.givenName} ${u.familyName}`.trim() || u.email;

// Mirror of Segment::keyToRole on the server so the create form can preview
// the synthetic role as the admin types the key.
export const keyToRole = (key: string): string =>
  `ROLE_SEGMENT_${key.toUpperCase().replace(/-/g, "_")}`;

// Accept what the user types and coerce it toward a valid key
// (lowercase, dashes for runs of non-alphanumerics).
export const normalizeKey = (raw: string): string =>
  raw
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

// API Platform collections come back as JSON-LD; tolerate both shapes.
export const readCollection = <T>(data: unknown): T[] => {
  if (Array.isArray(data)) return data as T[];
  if (data && typeof data === "object" && Array.isArray((data as { "hydra:member"?: T[] })["hydra:member"])) {
    return (data as { "hydra:member": T[] })["hydra:member"];
  }
  return [];
};
