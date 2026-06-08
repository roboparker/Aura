import { Plus, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import ComponentDoc from "@/components/dev/ComponentDoc";

const ButtonPage = () => (
  <ComponentDoc
    name="Button"
    description="Variants and sizes built on class-variance-authority. Pass `asChild` to render the styles on an inner element (e.g. <Link>)."
    importPath={`import { Button } from "@/components/ui/button";`}
    examples={[
      {
        title: "Variants",
        code: `<Button>Default</Button>
<Button variant="secondary">Secondary</Button>
<Button variant="outline">Outline</Button>
<Button variant="ghost">Ghost</Button>
<Button variant="destructive">Destructive</Button>
<Button variant="link">Link</Button>`,
        preview: (
          <div className="flex flex-wrap gap-2">
            <Button>Default</Button>
            <Button variant="secondary">Secondary</Button>
            <Button variant="outline">Outline</Button>
            <Button variant="ghost">Ghost</Button>
            <Button variant="destructive">Destructive</Button>
            <Button variant="link">Link</Button>
          </div>
        ),
      },
      {
        title: "Sizes",
        code: `<Button size="sm">Small</Button>
<Button>Default</Button>
<Button size="lg">Large</Button>
<Button size="icon" aria-label="Add"><Plus /></Button>
{/* icon-xs (h-5 w-5) — used internally by Combobox/InputGroup for inline trigger + chip-remove buttons */}
<Button size="icon-xs" variant="ghost" aria-label="Remove"><X /></Button>`,
        preview: (
          <div className="flex flex-wrap items-center gap-2">
            <Button size="sm">Small</Button>
            <Button>Default</Button>
            <Button size="lg">Large</Button>
            <Button size="icon" aria-label="Add"><Plus /></Button>
            <Button size="icon-xs" variant="ghost" aria-label="Remove"><X /></Button>
          </div>
        ),
      },
      {
        title: "Disabled",
        code: `<Button disabled>Saving…</Button>`,
        preview: <Button disabled>Saving…</Button>,
      },
    ]}
  />
);

export default ButtonPage;
