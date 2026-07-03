/**
 * Per-space custom roles (#space-roles). Mirrors the backend
 * `App\Security\Permission\SpacePermission` vocabulary + the `SpaceRole`
 * ApiResource shape.
 */

export const PERMISSION_ACTIONS = [
  "create",
  "read",
  "update",
  "delete",
] as const;
export type PermissionAction = (typeof PERMISSION_ACTIONS)[number];

export const PERMISSION_CATEGORIES = [
  "projects",
  "tasks",
  "pages",
  "discussions",
  "comments",
  "custom_fields",
  "tags",
  "groups",
  "files",
  "api_keys",
  "time_entries",
  "invoices",
] as const;
export type PermissionCategory = (typeof PERMISSION_CATEGORIES)[number];

export const CATEGORY_LABELS: Record<PermissionCategory, string> = {
  projects: "Projects",
  tasks: "Tasks",
  pages: "Pages",
  discussions: "Discussions",
  comments: "Comments",
  custom_fields: "Custom fields",
  tags: "Tags",
  groups: "Groups",
  files: "Files",
  api_keys: "API keys",
  time_entries: "Time tracking",
  invoices: "Invoicing",
};

export const ACTION_LABELS: Record<PermissionAction, string> = {
  create: "Create",
  read: "Read",
  update: "Update",
  delete: "Delete",
};

/** `{category: {action: bool}}`; a missing entry reads as denied. */
export type PermissionMatrix = Partial<
  Record<PermissionCategory, Partial<Record<PermissionAction, boolean>>>
>;

export interface SpaceRole {
  "@id": string;
  id: string;
  name: string;
  color: string | null;
  permissions: PermissionMatrix;
  /** 'member' | 'guest' for built-in roles (non-deletable); null for custom. */
  builtinKey: string | null;
  createdAt?: string;
}

/** The built-in "Member" role is the default for members with no assigned role. */
export const isDefaultMemberRole = (role: SpaceRole): boolean =>
  role.builtinKey === "member";

/** The embedded role summary on a SpaceMembership (`space:read`). */
export interface SpaceRoleRef {
  "@id": string;
  id: string;
  name: string;
  color: string | null;
}

export interface SpaceRoleCollection {
  member?: SpaceRole[];
  "hydra:member"?: SpaceRole[];
}

/** A fully-false matrix — the starting point for a new role. */
export const emptyMatrix = (): PermissionMatrix => {
  const matrix: PermissionMatrix = {};
  for (const category of PERMISSION_CATEGORIES) {
    matrix[category] = { create: false, read: false, update: false, delete: false };
  }
  return matrix;
};

export const matrixAllows = (
  matrix: PermissionMatrix,
  category: PermissionCategory,
  action: PermissionAction,
): boolean => matrix[category]?.[action] === true;
