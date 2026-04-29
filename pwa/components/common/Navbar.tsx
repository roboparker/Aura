import Link from "next/link";
import { useRouter } from "next/router";
import { Menu } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { displayName } from "@/lib/userDisplay";
import UserAvatar from "@/components/user/UserAvatar";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { cn } from "@/lib/utils";
import ThemeToggle from "./ThemeToggle";

const NAV_LINKS = [
  { href: "/tasks", label: "Tasks" },
  { href: "/projects", label: "Projects" },
  { href: "/groups", label: "Groups" },
  { href: "/tags", label: "Tags" },
];

// Backend tooling surfaced inside the PWA chrome. These are served by
// Caddy (FrankenPHP), not Next.js, so they're regular <a> links rather
// than <Link>s. The API doc browser is public; the Mercure debugger is
// admin-only.
const PUBLIC_EXTERNAL_LINKS = [{ href: "/docs", label: "API" }];
const ADMIN_EXTERNAL_LINKS = [
  { href: "/.well-known/mercure/ui/", label: "Mercure" },
];

const Navbar = () => {
  const { user, isAuthenticated, logout } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");

  return (
    <nav className="border-b bg-background">
      <div className="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
        <div className="flex items-center gap-1">
          <Link
            href="/"
            className="font-bold text-lg no-underline text-foreground mr-2"
          >
            Aura
          </Link>
          {PUBLIC_EXTERNAL_LINKS.map(({ href, label }) => (
            <Button key={href} asChild variant="ghost" size="sm">
              <a href={href} target="_blank" rel="noopener noreferrer">
                {label}
              </a>
            </Button>
          ))}
        </div>

        <div className="flex items-center gap-1">
          {isAuthenticated && user ? (
            <Sheet>
              <ThemeToggle />
              <SheetTrigger asChild>
                <Button
                  variant="ghost"
                  size="sm"
                  className="gap-2"
                  aria-label="Open my account menu"
                >
                  <UserAvatar user={user} size="sm" />
                  <Menu className="h-4 w-4" />
                </Button>
              </SheetTrigger>
              <SheetContent side="right" className="w-72 sm:max-w-sm">
                <SheetHeader>
                  <SheetTitle>{displayName(user)}</SheetTitle>
                  <SheetDescription className="truncate">{user.email}</SheetDescription>
                </SheetHeader>
                <nav className="flex flex-col gap-1 px-2 pb-4">
                  <div className="grid grid-cols-2 gap-2 px-2 pb-2">
                    <SheetClose asChild>
                      <Button asChild variant="outline" size="sm">
                        <Link href="/account">My Account</Link>
                      </Button>
                    </SheetClose>
                    <Button variant="outline" size="sm" onClick={logout}>
                      Sign Out
                    </Button>
                  </div>
                  <Separator className="my-2" />
                  {NAV_LINKS.map((link) => {
                    const active = router.pathname.startsWith(link.href);
                    return (
                      <SheetClose key={link.href} asChild>
                        <Button
                          asChild
                          variant="ghost"
                          size="sm"
                          className={cn(
                            "justify-start",
                            active && "bg-accent text-accent-foreground",
                          )}
                        >
                          <Link href={link.href}>{link.label}</Link>
                        </Button>
                      </SheetClose>
                    );
                  })}
                  {isAdmin && (
                    <>
                      <Separator className="my-2" />
                      <p className="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        Admin
                      </p>
                      <SheetClose asChild>
                        <Button
                          asChild
                          variant="ghost"
                          size="sm"
                          className={cn(
                            "justify-start",
                            router.pathname.startsWith("/admin") &&
                              "bg-accent text-accent-foreground",
                          )}
                        >
                          <Link href="/admin">Admin</Link>
                        </Button>
                      </SheetClose>
                      {ADMIN_EXTERNAL_LINKS.map(({ href, label }) => (
                        <Button
                          key={href}
                          asChild
                          variant="ghost"
                          size="sm"
                          className="justify-start"
                        >
                          <a href={href} target="_blank" rel="noopener noreferrer">
                            {label}
                          </a>
                        </Button>
                      ))}
                    </>
                  )}
                </nav>
              </SheetContent>
            </Sheet>
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
