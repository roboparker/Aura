import type {
  PermissionLevel,
  PermissionNode,
} from "@/components/common/PermissionTree";

/**
 * Shared permission config for the API-token create + edit dialogs. Both the
 * "Generate token" and "Edit permissions" flows render the same none/view/edit
 * tree, so the level + node definitions live here rather than being duplicated.
 */
export const TOKEN_PERMISSION_LEVELS: PermissionLevel[] = [
  { value: "none", label: "None" },
  { value: "view", label: "View" },
  { value: "edit", label: "Edit" },
];

export const TOKEN_PERMISSION_NODES: PermissionNode[] = [
  {
    key: "content",
    label: "Content",
    description: "tasks, boards, pages, discussions, comments, files",
    children: [
      { key: "tasks", label: "Tasks" },
      { key: "boards", label: "Boards" },
      { key: "pages", label: "Pages" },
      { key: "discussions", label: "Discussions" },
      { key: "comments", label: "Comments" },
      { key: "files", label: "Files" },
    ],
  },
  {
    key: "settings",
    label: "Settings",
    description: "notifications, profile, security",
    children: [
      { key: "notifications", label: "Notifications" },
      { key: "profile", label: "Profile" },
      { key: "security", label: "Security" },
    ],
  },
];

export const TOKEN_CATEGORIES = [
  "tasks",
  "boards",
  "pages",
  "discussions",
  "comments",
  "notifications",
  "files",
  "profile",
  "security",
];

// When a token is restricted, start everything at read-only — a useful,
// least-surprising baseline the user tightens or loosens.
export const defaultTokenAccess = (): Record<string, string> =>
  Object.fromEntries(TOKEN_CATEGORIES.map((c) => [c, "view"]));
