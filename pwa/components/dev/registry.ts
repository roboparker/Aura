export type RegistryEntry = {
  slug: string;
  name: string;
  category:
    | "Primitive"
    | "Form"
    | "Overlay"
    | "Data"
    | "Editor"
    | "User"
    | "Layout"
    | "Discussions"
    | "Custom fields"
    | "Groups"
    | "Notifications";
  description: string;
};

// Single source of truth for the /dev/components index. Keep this list in
// sync with the per-slug pages under pages/dev/components/.
export const componentRegistry: RegistryEntry[] = [
  { slug: "alert", name: "Alert", category: "Primitive", description: "Inline message panel for notices and errors." },
  { slug: "badge", name: "Badge", category: "Primitive", description: "Small label for statuses and counts." },
  { slug: "button", name: "Button", category: "Primitive", description: "Variants, sizes, and icon-only buttons." },
  { slug: "callout-badge", name: "CalloutBadge", category: "Primitive", description: "Square tinted box used as the headline icon/label for status callouts." },
  { slug: "calendar", name: "Calendar", category: "Form", description: "Date picker built on react-day-picker." },
  { slug: "card", name: "Card", category: "Primitive", description: "Surface container with header, content, and footer slots." },
  { slug: "checkbox", name: "Checkbox", category: "Form", description: "Boolean input with controlled and indeterminate states." },
  { slug: "combobox", name: "Combobox", category: "Form", description: "Searchable select with multi-value chips." },
  { slug: "command", name: "Command", category: "Overlay", description: "Command palette built on cmdk." },
  { slug: "dialog", name: "Dialog", category: "Overlay", description: "Modal dialog with title, body, and actions." },
  { slug: "dropdown-menu", name: "Dropdown Menu", category: "Overlay", description: "Anchored menu with items, separators, and submenus." },
  { slug: "formik-field", name: "FormikField", category: "Form", description: "Formik-aware label + input + error wrapper." },
  { slug: "input", name: "Input", category: "Form", description: "Single-line text input." },
  { slug: "input-group", name: "InputGroup", category: "Form", description: "Compose an input with leading/trailing addons." },
  { slug: "input-otp", name: "InputOTP", category: "Form", description: "Segmented one-time-code input (slots + separator) built on input-otp." },
  { slug: "color-swatch-picker", name: "ColorSwatchPicker", category: "Form", description: "Stateless radiogroup row of color swatches with a saving pulse state." },
  { slug: "email-chip-input", name: "EmailChipInput", category: "Form", description: "Controlled multi-email chip input with paste-split, validation ring, and colored tiles." },
  { slug: "label", name: "Label", category: "Form", description: "Accessible label associated to a form control." },
  { slug: "permission-tree", name: "PermissionTree", category: "Form", description: "Reusable nested None/Custom/All permission editor over a flat leaf→level map." },
  { slug: "popover", name: "Popover", category: "Overlay", description: "Floating content anchored to a trigger." },
  { slug: "separator", name: "Separator", category: "Primitive", description: "Horizontal or vertical divider." },
  { slug: "sheet", name: "Sheet", category: "Overlay", description: "Side-anchored drawer." },
  { slug: "switch", name: "Switch", category: "Form", description: "On/off toggle built on @base-ui/react." },
  { slug: "table", name: "Table", category: "Data", description: "Styled HTML table primitives." },
  { slug: "comments-panel", name: "CommentsPanel", category: "Data", description: "Flat chronological comment thread with composer, inline edit/delete, and a lockable composer." },
  { slug: "tabs", name: "Tabs", category: "Primitive", description: "Tab list + panels." },
  { slug: "textarea", name: "Textarea", category: "Form", description: "Multi-line text input." },
  { slug: "markdown-editor", name: "MarkdownEditor", category: "Editor", description: "BlockNote-backed WYSIWYG markdown editor." },
  { slug: "markdown-view", name: "MarkdownView", category: "Editor", description: "Read-only markdown renderer." },
  { slug: "code-fence", name: "CodeFence", category: "Editor", description: "Syntax-highlighted code block with copy button and per-page theme switcher." },
  { slug: "code-theme-switcher", name: "CodeThemeSwitcher", category: "Editor", description: "Dropdown to pick the Prism palette for code fences; persisted to localStorage." },
  { slug: "user-avatar", name: "UserAvatar", category: "User", description: "Avatar with image fallback to personalized initials." },
  { slug: "avatar-stack", name: "AvatarStack", category: "User", description: "Overlapping avatar cluster with a +N overflow chip." },
  { slug: "page-header", name: "PageHeader", category: "Layout", description: "Shared top-of-page header: title, optional subtitle, right-aligned actions, divider." },
  { slug: "impersonation-banner", name: "ImpersonationBanner", category: "Layout", description: "Amber bar shown while an admin impersonates another user; one-click stop. Renders nothing otherwise." },
  { slug: "layout", name: "Layout", category: "Layout", description: "App-shell wrapper: providers + persistent Sidebar, Navbar, and Footer." },
  { slug: "navbar", name: "Navbar", category: "Layout", description: "Top app bar with breadcrumbs/wordmark, search, badges, and mobile sheet." },
  { slug: "breadcrumbs", name: "Breadcrumbs", category: "Layout", description: "URL-derived navbar breadcrumb trail; lazy-fetches entity names for UUID segments." },
  { slug: "footer", name: "Footer", category: "Layout", description: "Static site footer with product, developer, and project link columns." },
  { slug: "sidebar", name: "Sidebar", category: "Layout", description: "Persistent left navigation column (md and up, authenticated only)." },
  { slug: "sidebar-nav", name: "SidebarNav", category: "Layout", description: "Shared nav contents used by the persistent sidebar and the mobile sheet." },
  { slug: "user-menu", name: "UserMenu", category: "Layout", description: "Top-bar account menu: square avatar + chevron trigger over a dropdown with name/email, personal links, and sign out." },
  { slug: "search-bar", name: "SearchBar", category: "Layout", description: "Navbar task search with debounced autocomplete; ⌘K opens the SearchOverlay palette." },
  { slug: "search-overlay", name: "SearchOverlay", category: "Layout", description: "⌘K command palette: suggestion chips, recent searches, keyboard nav." },
  { slug: "space-switcher", name: "SpaceSwitcher", category: "Layout", description: "Active-space dropdown that persists the choice via ActiveSpaceContext." },
  { slug: "discussions-panel", name: "DiscussionsPanel", category: "Discussions", description: "Space discussion board: search, category filters, pinned/all table, participant stacks, reply counts, inline pin/lock/delete." },
  { slug: "category-badge", name: "CategoryBadge", category: "Discussions", description: "Colored-dot pill naming a discussion's category." },
  { slug: "custom-fields-manager", name: "CustomFieldsManager", category: "Custom fields", description: "Owner-managed list + composer for per-project custom field definitions." },
  { slug: "group-tile", name: "GroupTile", category: "Groups", description: "Square colored tile with a grid glyph for a UserGroup avatar." },
  { slug: "notification-row", name: "NotificationRow", category: "Notifications", description: "Inbox row with unread/read/selected states and hover quick-actions." },
];
