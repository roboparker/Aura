import Navbar from "@/components/common/Navbar";
import ComponentDoc from "@/components/dev/ComponentDoc";

const NavbarPage = () => (
  <ComponentDoc
    name="Navbar"
    description="Top app bar: Aura wordmark + Docs dropdown on the left, SearchBar in the middle (auth-only), OverdueBadge + NotificationBell on the right, and a mobile Sheet trigger that mounts SidebarNav inside a slide-over. Signed-out visitors see Sign In / Sign Up buttons instead of the badges and bell."
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
