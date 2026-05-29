import Link from "next/link";
import { Menu } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import NotificationBell from "@/components/notifications/NotificationBell";
import OverdueBadge from "@/components/tasks/OverdueBadge";
import SearchBar from "./SearchBar";
import SidebarNav from "./SidebarNav";
import ThemeToggle from "./ThemeToggle";

const Navbar = () => {
  const { isAuthenticated } = useAuth();

  return (
    <nav className="border-b bg-background">
      <div className="px-4 py-3 flex items-center gap-3">
        <div className="flex items-center gap-1 shrink-0">
          <Link
            href="/"
            className="font-bold text-lg no-underline text-foreground mr-2"
          >
            Aura
          </Link>
          {/* Developer-facing doc links (API reference, component
              library, guides) live in the page Footer to keep the navbar
              focused on the active session. */}
        </div>

        {/* SearchBar stretches into the empty space between the
            wordmark/Docs group and the right-side action cluster.
            `max-w-3xl` keeps it from looking comical on very wide
            viewports. Hidden on small screens via SearchBar's own
            `hidden sm:block`. */}
        {isAuthenticated && (
          <SearchBar className="flex-1 min-w-0 max-w-3xl" />
        )}

        <div className="flex items-center gap-1 shrink-0 ml-auto">
          {isAuthenticated ? (
            <>
              <OverdueBadge enabled={isAuthenticated} />
              <NotificationBell enabled={isAuthenticated} />
              <ThemeToggle />
              {/* Mobile-only entry to the same nav surface — the
                  persistent Sidebar takes over on `md` and up. */}
              <Sheet>
                <SheetTrigger asChild>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="md:hidden"
                    aria-label="Open menu"
                  >
                    <Menu className="h-4 w-4" />
                  </Button>
                </SheetTrigger>
                <SheetContent side="left" className="w-72 p-0 sm:max-w-sm">
                  <SheetHeader className="sr-only">
                    <SheetTitle>Main navigation</SheetTitle>
                  </SheetHeader>
                  <SidebarNav
                    itemWrapper={(child) => (
                      <SheetClose asChild>{child}</SheetClose>
                    )}
                  />
                </SheetContent>
              </Sheet>
            </>
          ) : (
            <>
              <Button asChild variant="ghost" size="sm">
                <Link href="/signin">Sign In</Link>
              </Button>
              <Button asChild size="sm">
                <Link href="/signup">Sign Up</Link>
              </Button>
              <ThemeToggle />
            </>
          )}
        </div>
      </div>
    </nav>
  );
};

export default Navbar;
