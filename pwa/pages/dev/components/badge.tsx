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
    ]}
  />
);

export default BadgePage;
