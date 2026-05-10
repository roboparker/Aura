import Link from "next/link";
import { ChevronDown, Menu } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
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
import SpaceSwitcher from "./SpaceSwitcher";
import ThemeToggle from "./ThemeToggle";

const Navbar = () => {
  const { isAuthenticated } = useAuth();

  return (
    <nav className="border-b bg-background">
      <div className="px-4 py-3 flex items-center justify-between">
        <div className="flex items-center gap-1">
          <Link
            href="/"
            className="font-bold text-lg no-underline text-foreground mr-2"
          >
            Aura
          </Link>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" className="gap-1">
                Docs
                <ChevronDown className="h-3.5 w-3.5" aria-hidden />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
              <DropdownMenuItem asChild>
                <a href="/docs">API</a>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <Link href="/dev/components">Components</Link>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          {isAuthenticated && <SpaceSwitcher />}
        </div>

        <div className="flex items-center gap-1">
          {isAuthenticated ? (
            <>
              <SearchBar />
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
