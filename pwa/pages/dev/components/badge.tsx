import { Badge } from "@/components/ui/badge";
import ComponentDoc from "@/components/dev/ComponentDoc";

const BadgePage = () => (
  <ComponentDoc
    name="Badge"
    description="Small label for statuses, counts, and metadata."
    importPath={`import { Badge } from "@/components/ui/badge";`}
    examples={[
      {
        title: "Variants",
        description:
          "Tinted-translucent by default, so a column of chips in a data table stays scannable.",
        code: `<Badge>Default</Badge>
<Badge variant="secondary">Secondary</Badge>
<Badge variant="destructive">Destructive</Badge>
<Badge variant="outline">Outline</Badge>`,
        preview: (
          <div className="flex flex-wrap gap-2">
            <Badge>Default</Badge>
            <Badge variant="secondary">Secondary</Badge>
            <Badge variant="destructive">Destructive</Badge>
            <Badge variant="outline">Outline</Badge>
          </div>
        ),
      },
      {
        title: "Solid (interrupting states)",
        description:
          "Reserve solid fills for states that genuinely need to interrupt — overdue, unpaid, failed. Using them for routine metadata flattens hierarchy and the tinted variants stop reading as calm.",
        code: `<Badge variant="solid">Paid</Badge>
<Badge variant="solidDestructive">Overdue</Badge>`,
        preview: (
          <div className="flex flex-wrap gap-2">
            <Badge variant="solid">Paid</Badge>
            <Badge variant="solidDestructive">Overdue</Badge>
          </div>
        ),
      },
    ]}
  />
);

export default BadgePage;
