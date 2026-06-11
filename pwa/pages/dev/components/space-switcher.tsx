import SpaceSwitcher from "@/components/common/SpaceSwitcher";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SpaceSwitcherPage = () => (
  <ComponentDoc
    name="SpaceSwitcher"
    description="Active-space dropdown rendered at the top of SidebarNav (under the user header, above the personal links). Picks the user's current space and persists the choice via ActiveSpaceContext (per-user localStorage key `aura.activeSpaceId.{userId}`, reset to the Private space on a fresh sign-in), so listing pages (`/projects`, `/discussions`) scope to it. The dropdown lists spaces by recency, selecting one navigates to `/spaces/{id}`, and a divider adds shortcuts to All spaces (/spaces) and Create space (/spaces/new). Personal spaces wear a lock icon. Renders 'Loading…' until the space list resolves and nothing if the user has no active space."
    importPath={`import SpaceSwitcher from "@/components/common/SpaceSwitcher";`}
    examples={[
      {
        title: "Default (in the sidebar)",
        code: `<SpaceSwitcher />`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted by <code className="mx-1">SidebarNav</code> for
            authenticated users with at least one space loaded. The switcher is
            already visible at the top of the sidebar on the left of this page.
          </p>
        ),
      },
    ]}
  />
);

void SpaceSwitcher;

export default SpaceSwitcherPage;
