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
    | "Theme"
    | "Discussions"
    | "Custom fields";
  description: string;
};

// Single source of truth for the /dev/components index. Keep this list in
// sync with the per-slug pages under pages/dev/components/.
export const componentRegistry: RegistryEntry[] = [
  { slug: "alert", name: "Alert", category: "Primitive", description: "Inline message panel for notices and errors." },
  { slug: "badge", name: "Badge", category: "Primitive", description: "Small label for statuses and counts." },
  { slug: "button", name: "Button", category: "Primitive", description: "Variants, sizes, and icon-only buttons." },
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
  { slug: "label", name: "Label", category: "Form", description: "Accessible label associated to a form control." },
  { slug: "popover", name: "Popover", category: "Overlay", description: "Floating content anchored to a trigger." },
  { slug: "separator", name: "Separator", category: "Primitive", description: "Horizontal or vertical divider." },
  { slug: "sheet", name: "Sheet", category: "Overlay", description: "Side-anchored drawer." },
  { slug: "table", name: "Table", category: "Data", description: "Styled HTML table primitives." },
  { slug: "tabs", name: "Tabs", category: "Primitive", description: "Tab list + panels." },
  { slug: "textarea", name: "Textarea", category: "Form", description: "Multi-line text input." },
  { slug: "markdown-editor", name: "MarkdownEditor", category: "Editor", description: "BlockNote-backed WYSIWYG markdown editor." },
  { slug: "markdown-view", name: "MarkdownView", category: "Editor", description: "Read-only markdown renderer." },
  { slug: "user-avatar", name: "UserAvatar", category: "User", description: "Avatar with image fallback to personalized initials." },
  { slug: "discussions-panel", name: "DiscussionsPanel", category: "Discussions", description: "Project discussion board with list, filter, create, edit, pin/lock." },
  { slug: "custom-fields-manager", name: "CustomFieldsManager", category: "Custom fields", description: "Owner-managed list + composer for per-project custom field definitions." },
  { slug: "theme-toggle", name: "ThemeToggle", category: "Theme", description: "Dark/light/system theme switcher." },
];
