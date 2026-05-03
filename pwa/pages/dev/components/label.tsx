import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import ComponentDoc from "@/components/dev/ComponentDoc";

const LabelPage = () => (
  <ComponentDoc
    name="Label"
    description="Accessible label associated to a form control via `htmlFor`. Built on @radix-ui/react-label."
    importPath={`import { Label } from "@/components/ui/label";`}
    examples={[
      {
        title: "Paired with an input",
        code: `<div className="space-y-1.5">
  <Label htmlFor="email">Email</Label>
  <Input id="email" type="email" placeholder="you@example.com" />
</div>`,
        preview: (
          <div className="w-72 space-y-1.5">
            <Label htmlFor="email">Email</Label>
            <Input id="email" type="email" placeholder="you@example.com" />
          </div>
        ),
      },
    ]}
  />
);

export default LabelPage;
