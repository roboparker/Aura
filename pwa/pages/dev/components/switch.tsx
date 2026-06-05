import { useState } from "react";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import ComponentDoc from "@/components/dev/ComponentDoc";

const Controlled = () => {
  const [checked, setChecked] = useState(true);
  return (
    <div className="flex items-center gap-2">
      <Switch id="notify" checked={checked} onCheckedChange={setChecked} />
      <Label htmlFor="notify">Push notifications</Label>
    </div>
  );
};

const SwitchPage = () => (
  <ComponentDoc
    name="Switch"
    description="Toggle built on the @base-ui/react Switch primitive (same library as Combobox). Controlled via `checked` / `onCheckedChange`; pair with a Label using shared `id`/`htmlFor`."
    importPath={`import { Switch } from "@/components/ui/switch";`}
    examples={[
      {
        title: "Controlled",
        code: `const [checked, setChecked] = useState(true);

<div className="flex items-center gap-2">
  <Switch id="notify" checked={checked} onCheckedChange={setChecked} />
  <Label htmlFor="notify">Push notifications</Label>
</div>`,
        preview: <Controlled />,
      },
      {
        title: "Disabled",
        code: `<Switch disabled />`,
        preview: <Switch disabled />,
      },
    ]}
  />
);

export default SwitchPage;
