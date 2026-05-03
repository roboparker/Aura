import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import ComponentDoc from "@/components/dev/ComponentDoc";

const InputPage = () => (
  <ComponentDoc
    name="Input"
    description="Single-line text input. Forwards refs and accepts every native <input> attribute."
    importPath={`import { Input } from "@/components/ui/input";`}
    examples={[
      {
        title: "Default",
        code: `<Input placeholder="Type something…" />`,
        preview: <Input placeholder="Type something…" className="max-w-sm" />,
      },
      {
        title: "With label",
        code: `<div className="space-y-1.5">
  <Label htmlFor="username">Username</Label>
  <Input id="username" placeholder="ada-lovelace" />
</div>`,
        preview: (
          <div className="w-72 space-y-1.5">
            <Label htmlFor="username">Username</Label>
            <Input id="username" placeholder="ada-lovelace" />
          </div>
        ),
      },
      {
        title: "Disabled",
        code: `<Input disabled value="Locked" />`,
        preview: <Input disabled defaultValue="Locked" className="max-w-sm" />,
      },
    ]}
  />
);

export default InputPage;
