import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import ComponentDoc from "@/components/dev/ComponentDoc";

const TextareaPage = () => (
  <ComponentDoc
    name="Textarea"
    description="Multi-line text input. Use MarkdownEditor when the content is long-form prose."
    importPath={`import { Textarea } from "@/components/ui/textarea";`}
    examples={[
      {
        title: "With label",
        code: `<div className="space-y-1.5">
  <Label htmlFor="bio">Bio</Label>
  <Textarea id="bio" placeholder="A short paragraph about you…" rows={4} />
</div>`,
        preview: (
          <div className="w-full max-w-md space-y-1.5">
            <Label htmlFor="bio">Bio</Label>
            <Textarea id="bio" placeholder="A short paragraph about you…" rows={4} />
          </div>
        ),
      },
    ]}
  />
);

export default TextareaPage;
