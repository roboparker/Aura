import { Search, AtSign } from "lucide-react";
import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupInput,
  InputGroupText,
  InputGroupTextarea,
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
      {
        title: "Trailing button",
        code: `<InputGroup>
  <InputGroupInput placeholder="Add a comment…" />
  <InputGroupAddon align="inline-end">
    <InputGroupButton variant="default" size="sm">Post</InputGroupButton>
  </InputGroupAddon>
</InputGroup>`,
        preview: (
          <div className="w-full max-w-sm">
            <InputGroup>
              <InputGroupInput placeholder="Add a comment…" />
              <InputGroupAddon align="inline-end">
                <InputGroupButton variant="default" size="sm">Post</InputGroupButton>
              </InputGroupAddon>
            </InputGroup>
          </div>
        ),
      },
      {
        title: "Textarea with block-end addon",
        code: `// align="block-start" / "block-end" stack the addon above/below a
// multiline control instead of beside it.
<InputGroup>
  <InputGroupTextarea placeholder="Write a description…" />
  <InputGroupAddon align="block-end">
    <InputGroupText>Markdown supported</InputGroupText>
    <InputGroupButton size="sm" className="ml-auto">Save</InputGroupButton>
  </InputGroupAddon>
</InputGroup>`,
        preview: (
          <div className="w-full max-w-sm">
            <InputGroup>
              <InputGroupTextarea placeholder="Write a description…" />
              <InputGroupAddon align="block-end">
                <InputGroupText>Markdown supported</InputGroupText>
                <InputGroupButton size="sm" className="ml-auto">Save</InputGroupButton>
              </InputGroupAddon>
            </InputGroup>
          </div>
        ),
      },
    ]}
  />
);

export default InputGroupPage;
