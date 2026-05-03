import ThemeToggle from "@/components/common/ThemeToggle";
import ComponentDoc from "@/components/dev/ComponentDoc";

const ThemeTogglePage = () => (
  <ComponentDoc
    name="ThemeToggle"
    description="Sun/moon button that flips between light and dark via next-themes. Mounted in the navbar; safe to drop anywhere inside a ThemeProvider."
    importPath={`import ThemeToggle from "@/components/common/ThemeToggle";`}
    examples={[
      {
        title: "Default",
        code: `<ThemeToggle />`,
        preview: <ThemeToggle />,
      },
    ]}
  />
);

export default ThemeTogglePage;
