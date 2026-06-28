import AssigneePlaceholder from "@/components/user/AssigneePlaceholder";
import ComponentDoc from "@/components/dev/ComponentDoc";

const AssigneePlaceholderPage = () => (
  <ComponentDoc
    name="AssigneePlaceholder"
    description="Anonymous 'no one assigned' placeholder avatar — a dashed square with a UserPlus glyph. Shared by the board cards and the list assignee field so the empty-assignee affordance looks the same everywhere. Presentation only; the caller wires the click (open an assign menu) and any hover styling via `className`."
    importPath={`import AssigneePlaceholder from "@/components/user/AssigneePlaceholder";`}
    examples={[
      {
        title: "Default",
        code: `<AssigneePlaceholder />`,
        preview: <AssigneePlaceholder />,
      },
      {
        title: "With hover affordance",
        code: `<AssigneePlaceholder className="hover:border-foreground/40 hover:text-foreground" />`,
        preview: (
          <AssigneePlaceholder className="hover:border-foreground/40 hover:text-foreground" />
        ),
      },
    ]}
  />
);

export default AssigneePlaceholderPage;
