import { useState } from "react";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import ComponentDoc from "@/components/dev/ComponentDoc";

const Controlled = () => {
  const [checked, setChecked] = useState(true);
  return (
    <div className="flex items-center gap-2">
      <Checkbox id="subscribe" checked={checked} onCheckedChange={(v) => setChecked(Boolean(v))} />
      <Label htmlFor="subscribe">Email me about updates</Label>
    </div>
  );
};

const CheckboxPage = () => (
  <ComponentDoc
    name="Checkbox"
    description="Boolean input built on @radix-ui/react-checkbox. Pair with a Label using shared `id`/`htmlFor`."
    importPath={`import { Checkbox } from "@/components/ui/checkbox";`}
    examples={[
      {
        title: "Controlled",
        code: `const [checked, setChecked] = useState(true);

<div className="flex items-center gap-2">
  <Checkbox
    id="subscribe"
    checked={checked}
    onCheckedChange={(v) => setChecked(Boolean(v))}
  />
  <Label htmlFor="subscribe">Email me about updates</Label>
</div>`,
        preview: <Controlled />,
      },
      {
        title: "Disabled",
        code: `<Checkbox disabled />`,
        preview: <Checkbox disabled />,
      },
    ]}
  />
);

export default CheckboxPage;
