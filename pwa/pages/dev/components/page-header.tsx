import { Tag as TagIcon } from "lucide-react";
import PageHeader from "@/components/common/PageHeader";
import ComponentDoc from "@/components/dev/ComponentDoc";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

const PageHeaderPage = () => (
  <ComponentDoc
    name="PageHeader"
    description="Shared header for top-level routes. Standardises the visual rhythm at the top of every page — title, optional subtitle, an optional right-aligned action cluster, a divider, and a comfortable gap before the body. Use it instead of hand-rolling `<h1>` blocks so the spacing stays consistent across pages."
    importPath={`import PageHeader from "@/components/common/PageHeader";`}
    examples={[
      {
        title: "Title only",
        code: `<PageHeader title="Spaces" />`,
        preview: <PageHeader title="Spaces" />,
      },
      {
        title: "With subtitle",
        code: `<PageHeader
  title="Spaces"
  subtitle="Workspaces and shared rooms you belong to."
/>`,
        preview: (
          <PageHeader
            title="Spaces"
            subtitle="Workspaces and shared rooms you belong to."
          />
        ),
      },
      {
        title: "With actions",
        description:
          "The right-aligned action cluster baseline-aligns with the bottom of the subtitle.",
        code: `<PageHeader
  title="Spaces"
  subtitle="Workspaces and shared rooms you belong to."
  actions={
    <>
      <Button variant="outline">Filter</Button>
      <Button>New space</Button>
    </>
  }
/>`,
        preview: (
          <PageHeader
            title="Spaces"
            subtitle="Workspaces and shared rooms you belong to."
            actions={
              <>
                <Button variant="outline">Filter</Button>
                <Button>New space</Button>
              </>
            }
          />
        ),
      },
      {
        title: "Icon, action + search toolbar",
        description:
          "Pass `icon` for a leading glyph, `actions` for the top-right button, and `children` for a full-width toolbar (search / filters) below the title row.",
        code: `<PageHeader
  title="Tags"
  icon={<TagIcon className="h-6 w-6 text-cyan-600" />}
  subtitle="Shared across everyone in the space."
  actions={<Button size="sm">Add tag</Button>}
>
  <Input type="search" placeholder="Search tags…" className="w-full sm:max-w-md" />
</PageHeader>`,
        preview: (
          <PageHeader
            title="Tags"
            icon={<TagIcon className="h-6 w-6 text-cyan-600" />}
            subtitle="Shared across everyone in the space."
            actions={<Button size="sm">Add tag</Button>}
          >
            <Input
              type="search"
              placeholder="Search tags…"
              className="w-full sm:max-w-md"
            />
          </PageHeader>
        ),
      },
    ]}
  />
);

export default PageHeaderPage;
