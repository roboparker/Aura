import Navbar from "@/components/common/Navbar";
import ComponentDoc from "@/components/dev/ComponentDoc";

const NavbarPage = () => (
  <ComponentDoc
    name="Navbar"
    description="Full-width sticky top bar. Signed-out visitors get the Madori wordmark as the home anchor plus Sign In / Sign Up buttons. Signed-in viewers get the Breadcrumbs trail (replacing the wordmark) + SearchBar in the flexible middle, and on the right the OverdueBadge, NotificationBell, UserMenu (account dropdown), and a mobile-only Sheet trigger that mounts SidebarNav inside a slide-over. Developer doc links live in the Footer, not the navbar."
    importPath={`import Navbar from "@/components/common/Navbar";`}
    examples={[
      {
        title: "Rendered by Layout",
        code: `// components/common/Layout.tsx
<Navbar />`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted once by <code className="mx-1">Layout</code>. Previewing live
            here would render a second navbar below the real one — keep the
            example as a code snippet only.
          </p>
        ),
      },
    ]}
  />
);

void Navbar;

export default NavbarPage;
