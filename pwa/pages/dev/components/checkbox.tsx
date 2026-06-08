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

const Indeterminate = () => {
  const [items, setItems] = useState([true, false, true]);
  const allChecked = items.every(Boolean);
  const noneChecked = items.every((v) => !v);
  const parentState: boolean | "indeterminate" = allChecked
    ? true
    : noneChecked
      ? false
      : "indeterminate";
  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <Checkbox
          id="select-all"
          checked={parentState}
          onCheckedChange={(v) => setItems(items.map(() => v === true))}
        />
        <Label htmlFor="select-all">Select all</Label>
      </div>
      <div className="ml-6 space-y-1">
        {items.map((checked, i) => (
          <div key={i} className="flex items-center gap-2">
            <Checkbox
              id={`row-${i}`}
              checked={checked}
              onCheckedChange={(v) =>
                setItems(items.map((c, j) => (j === i ? v === true : c)))
              }
            />
            <Label htmlFor={`row-${i}`}>Row {i + 1}</Label>
          </div>
        ))}
      </div>
    </div>
  );
};

const CheckboxPage = () => (
  <ComponentDoc
    name="Checkbox"
    description="Boolean input built on @radix-ui/react-checkbox. Pair with a Label using shared `id`/`htmlFor`. Pass `checked=\"indeterminate\"` for the tri-state parent of a select-all group."
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
        title: "Indeterminate (select-all)",
        code: `const [items, setItems] = useState([true, false, true]);
const allChecked = items.every(Boolean);
const noneChecked = items.every((v) => !v);
const parentState: boolean | "indeterminate" = allChecked
  ? true
  : noneChecked
    ? false
    : "indeterminate";

<Checkbox
  checked={parentState}
  onCheckedChange={(v) => setItems(items.map(() => v === true))}
/>`,
        preview: <Indeterminate />,
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
