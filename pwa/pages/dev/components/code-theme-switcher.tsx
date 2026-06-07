import CodeThemeSwitcher from "@/components/editor/CodeThemeSwitcher";
import ComponentDoc from "@/components/dev/ComponentDoc";

const CodeThemeSwitcherPage = () => (
  <ComponentDoc
    name="CodeThemeSwitcher"
    description="Dropdown that picks which Prism palette renders every CodeFence on the page. The choice mutates `html[data-code-theme]` (CSS in globals.css does the rest) and persists to localStorage so the inline bootstrap script in _document.tsx restores it on the next load. The `full` variant is a labelled outline button for page-level use; the `compact` variant is the icon-only trigger embedded in the CodeFence header bar."
    importPath={`import CodeThemeSwitcher from "@/components/editor/CodeThemeSwitcher";`}
    examples={[
      {
        title: "Full (labelled button)",
        description:
          "Page-level control showing the active preset's name. Picking a theme re-paints every code fence on the page.",
        code: `<CodeThemeSwitcher variant="full" />`,
        preview: <CodeThemeSwitcher variant="full" />,
      },
      {
        title: "Compact (icon-only)",
        description:
          "The trigger used inside the CodeFence header next to the copy button.",
        code: `<CodeThemeSwitcher variant="compact" />`,
        preview: <CodeThemeSwitcher variant="compact" />,
      },
    ]}
  />
);

export default CodeThemeSwitcherPage;
