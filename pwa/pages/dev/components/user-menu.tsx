import UserMenu from "@/components/common/UserMenu";
import ComponentDoc from "@/components/dev/ComponentDoc";

const UserMenuPage = () => (
  <ComponentDoc
    name="UserMenu"
    description="Top-bar account menu. A square avatar + chevron button opens a dropdown headed by the signed-in user's name and email, followed by the personal links (My Tasks, Manage Groups, Settings) and Sign Out. Reads from AuthContext and renders nothing until a user is signed in."
    importPath={`import UserMenu from "@/components/common/UserMenu";`}
    examples={[
      {
        title: "Rendered in the Navbar",
        code: `<UserMenu />`,
        preview: (
          <p className="text-sm text-muted-foreground">
            Mounted on the right of the <code className="mx-1">Navbar</code> for
            authenticated sessions, next to the NotificationBell. The live
            instance is in this page&apos;s top bar.
          </p>
        ),
      },
    ]}
  />
);

void UserMenu;

export default UserMenuPage;
