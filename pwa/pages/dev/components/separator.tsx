import { Separator } from "@/components/ui/separator";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SeparatorPage = () => (
  <ComponentDoc
    name="Separator"
    description="Horizontal or vertical divider built on @radix-ui/react-separator."
    importPath={`import { Separator } from "@/components/ui/separator";`}
    examples={[
      {
        title: "Horizontal",
        code: `<div>
  <p className="text-sm">Section A</p>
  <Separator className="my-3" />
  <p className="text-sm">Section B</p>
</div>`,
        preview: (
          <div>
            <p className="text-sm">Section A</p>
            <Separator className="my-3" />
            <p className="text-sm">Section B</p>
          </div>
        ),
      },
      {
        title: "Vertical",
        code: `<div className="flex h-6 items-center gap-3 text-sm">
  <span>Inbox</span>
  <Separator orientation="vertical" />
  <span>Drafts</span>
  <Separator orientation="vertical" />
  <span>Sent</span>
</div>`,
        preview: (
          <div className="flex h-6 items-center gap-3 text-sm">
            <span>Inbox</span>
            <Separator orientation="vertical" />
            <span>Drafts</span>
            <Separator orientation="vertical" />
            <span>Sent</span>
          </div>
        ),
      },
    ]}
  />
);

export default SeparatorPage;
