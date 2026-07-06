import SidebarNav from "@/components/common/SidebarNav";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SidebarNavPage = () => (
  <ComponentDoc
    name="SidebarNav"
    description="Shared navigation surface used by both the persistent Sidebar (md and up) and the mobile Sheet variant in the Navbar. Renders the SpaceSwitcher at the top (the persistent Sidebar is full-height, so the switcher sits above the header); a content section per surface — Boards / Discussions list the active space's rows, while Pages renders a drag-to-reorder + nest tree (PagesNavTree) — each with a heading that links to its aggregator and an add link at the foot; standalone Tags and Custom fields items; a Settings section (General / Users / Roles / API keys — the first three admin-only, API keys gated on the api_keys permission); and an Admin section for ROLE_ADMIN users (Users, Waitlist, Segments, Feedback, and the external Mercure debugger). The account menu (avatar, personal links, sign out) and notification bell live in the top bar — see UserMenu / Navbar."
    importPath={`import SidebarNav from "@/components/common/SidebarNav";`}
    examples={[
      {
        title: "Persistent sidebar (default)",
        code: `<SidebarNav />`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Reads from AuthContext and ActiveSpaceContext — renders nothing
            until the visitor is signed in. Visible inside the real Sidebar on
            the left of this page.
          </p>
        ),
      },
      {
        title: "Mobile Sheet variant",
        description:
          "The Navbar's mobile menu wraps each link in a SheetClose so tapping a destination auto-dismisses the slide-over.",
        code: `<SidebarNav
  itemWrapper={(child) => (
    <SheetClose asChild>{child}</SheetClose>
  )}
/>`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Used inside the navbar's mobile <code className="mx-1">Sheet</code>{" "}
            (below the md breakpoint).
          </p>
        ),
      },
    ]}
  />
);

void SidebarNav;

export default SidebarNavPage;
