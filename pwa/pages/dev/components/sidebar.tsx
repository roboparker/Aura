import Sidebar from "@/components/common/Sidebar";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SidebarPage = () => (
  <ComponentDoc
    name="Sidebar"
    description="Persistent left-side navigation rendered by Layout at md and up. Wraps SidebarNav in a sticky 240px-wide column. Renders nothing when the visitor isn't authenticated — public marketing and auth screens keep their original full-width chrome."
    importPath={`import Sidebar from "@/components/common/Sidebar";`}
    examples={[
      {
        title: "Rendered by Layout",
        code: `// components/common/Layout.tsx
<div className="flex">
  <Sidebar />
  <div className="flex-1 min-w-0">{children}</div>
</div>`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted once by <code className="mx-1">Layout</code> and visible on
            the left of this very page (md and up). Previewing live would
            duplicate the active-page sidebar inside itself.
          </p>
        ),
      },
    ]}
  />
);

void Sidebar;

export default SidebarPage;
