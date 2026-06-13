import Link from "next/link";
import { Menu } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useSignupStatus } from "@/lib/useSignupStatus";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import OverdueBadge from "@/components/tasks/OverdueBadge";
import NotificationBell from "@/components/notifications/NotificationBell";
import SearchOverlay from "@/components/search/SearchOverlay";
import Breadcrumbs from "./Breadcrumbs";
import SearchBar from "./SearchBar";
import SidebarNav from "./SidebarNav";
import UserMenu from "./UserMenu";

const Navbar = () => {
  const { isAuthenticated } = useAuth();
  const { waitlistEnabled } = useSignupStatus();

  return (
    <nav className="h-14 w-full border-b bg-background">
      <div className="flex h-full items-center gap-3 px-4">
        <div className="flex items-center gap-1 shrink-0">
          {/* Signed-out viewers get the Madori wordmark as the home
              anchor; signed-in viewers get the Breadcrumbs trail
              below (with its own Home icon), so the wordmark would
              be redundant. Developer-facing doc links (API reference,
              component library, guides) live in the page Footer to
              keep the navbar focused on the active session. */}
          {!isAuthenticated && (
            <Link
              href="/"
              className="font-bold text-lg no-underline text-foreground mr-2"
            >
              Madori
            </Link>
          )}
        </div>

        {/* When signed in, the breadcrumb trail sits left of the
            search bar and replaces the wordmark as the home anchor.
            Both trail and search share the flexible middle column —
            the breadcrumb shrinks first so the search bar keeps a
            usable footprint on narrow viewports. */}
        {isAuthenticated && (
          <>
            <Breadcrumbs className="hidden md:flex flex-1 min-w-0" />
            <SearchBar className="flex-1 min-w-0 max-w-3xl" />
            <SearchOverlay />
          </>
        )}

        <div className="flex items-center gap-1 shrink-0 ml-auto">
          {isAuthenticated ? (
            <>
              <OverdueBadge enabled={isAuthenticated} />
              <NotificationBell enabled={isAuthenticated} />
              <UserMenu />
              {/* Mobile-only entry to the space/admin nav — the
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
                <Link href="/signup">
                  {waitlistEnabled ? "Join the waitlist" : "Sign Up"}
                </Link>
              </Button>
            </>
          )}
        </div>
      </div>
    </nav>
  );
};

export default Navbar;
