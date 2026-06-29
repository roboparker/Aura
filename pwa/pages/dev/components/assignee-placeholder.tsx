import AssigneePlaceholder from "@/components/user/AssigneePlaceholder";
import ComponentDoc from "@/components/dev/ComponentDoc";

const AssigneePlaceholderPage = () => (
  <ComponentDoc
    name="AssigneePlaceholder"
    description="Anonymous 'no one assigned' placeholder avatar — a dashed square with a UserPlus glyph, sized to match an actual assignee avatar (`sm` = 32px board/calendar cards, `xs` = 16px list chips). Shared by the board cards, calendar chips, and the list assignee field so the empty-assignee affordance looks the same everywhere. Presentation only; the caller wires the click (open an assign menu) and any hover styling via `className`."
    importPath={`import AssigneePlaceholder from "@/components/user/AssigneePlaceholder";`}
    examples={[
      {
        title: "Default (sm — matches a 32px assignee avatar)",
        code: `<AssigneePlaceholder />`,
        preview: <AssigneePlaceholder />,
      },
      {
        title: "xs (matches a 16px list chip)",
        code: `<AssigneePlaceholder size="xs" />`,
        preview: <AssigneePlaceholder size="xs" />,
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
