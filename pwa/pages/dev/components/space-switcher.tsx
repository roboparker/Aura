import SpaceSwitcher from "@/components/common/SpaceSwitcher";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SpaceSwitcherPage = () => (
  <ComponentDoc
    name="SpaceSwitcher"
    description="Active-space dropdown surfaced next to the Aura wordmark in the navbar. Picks the user's current space and persists the choice via ActiveSpaceContext (localStorage key `aura.activeSpaceId`), so listing pages (`/projects`, `/discussions`) scope to it. Personal spaces wear a lock icon. Renders 'Loading…' until the space list resolves and nothing if the user has no spaces."
    importPath={`import SpaceSwitcher from "@/components/common/SpaceSwitcher";`}
    examples={[
      {
        title: "Default (in navbar)",
        code: `<SpaceSwitcher />`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted by <code className="mx-1">Navbar</code> for authenticated
            users with at least one space loaded. The dropdown is already
            visible in the navbar at the top of this page.
          </p>
        ),
      },
    ]}
  />
);

void SpaceSwitcher;

export default SpaceSwitcherPage;
