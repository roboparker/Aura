import { Search, AtSign } from "lucide-react";
import {
  InputGroup,
  InputGroupAddon,
  InputGroupInput,
  InputGroupText,
} from "@/components/ui/input-group";
import ComponentDoc from "@/components/dev/ComponentDoc";

const InputGroupPage = () => (
  <ComponentDoc
    name="InputGroup"
    description="Compose an input with leading/trailing addons (icons, text, buttons). Used internally by Combobox."
    importPath={`import { InputGroup, InputGroupAddon, InputGroupInput, InputGroupText, InputGroupButton, InputGroupTextarea } from "@/components/ui/input-group";`}
    examples={[
      {
        title: "Leading icon",
        code: `<InputGroup>
  <InputGroupAddon align="inline-start">
    <Search />
  </InputGroupAddon>
  <InputGroupInput placeholder="Search tasks…" />
</InputGroup>`,
        preview: (
          <div className="w-full max-w-sm">
            <InputGroup>
              <InputGroupAddon align="inline-start">
                <Search />
              </InputGroupAddon>
              <InputGroupInput placeholder="Search tasks…" />
            </InputGroup>
          </div>
        ),
      },
      {
        title: "Trailing text addon",
        code: `<InputGroup>
  <InputGroupAddon align="inline-start">
    <AtSign />
  </InputGroupAddon>
  <InputGroupInput placeholder="username" />
  <InputGroupAddon align="inline-end">
    <InputGroupText>@aura.dev</InputGroupText>
  </InputGroupAddon>
</InputGroup>`,
        preview: (
          <div className="w-full max-w-sm">
            <InputGroup>
              <InputGroupAddon align="inline-start">
                <AtSign />
              </InputGroupAddon>
              <InputGroupInput placeholder="username" />
              <InputGroupAddon align="inline-end">
                <InputGroupText>@aura.dev</InputGroupText>
              </InputGroupAddon>
            </InputGroup>
          </div>
        ),
      },
    ]}
  />
);

export default InputGroupPage;
