import Link from "next/link";
import { useRouter } from "next/router";
import { useAuth } from "@/contexts/AuthContext";
import UserAvatar from "@/components/user/UserAvatar";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import ThemeToggle from "./ThemeToggle";

const NAV_LINKS = [
  { href: "/tasks", label: "Tasks" },
  { href: "/projects", label: "Projects" },
  { href: "/groups", label: "Groups" },
  { href: "/tags", label: "Tags" },
];

const Navbar = () => {
  const { user, isAuthenticated, logout } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");

  return (
    <nav className="border-b bg-background">
      <div className="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
        <Link href="/" className="font-bold text-lg no-underline text-foreground">
          Aura
        </Link>

        <div className="flex items-center gap-1">
          {isAuthenticated && user ? (
            <>
              {isAdmin && (
                <Button
                  asChild
                  variant="ghost"
                  size="sm"
                  className={cn(
                    router.pathname.startsWith("/admin") && "bg-accent text-accent-foreground",
                  )}
                >
                  <Link href="/admin">Admin</Link>
                </Button>
              )}
              {NAV_LINKS.map((link) => {
                const active = router.pathname.startsWith(link.href);
                return (
                  <Button
                    key={link.href}
                    asChild
                    variant="ghost"
                    size="sm"
                    className={cn(active && "bg-accent text-accent-foreground")}
                  >
                    <Link href={link.href}>{link.label}</Link>
                  </Button>
                );
              })}
              <Button variant="ghost" size="sm" onClick={logout}>
                Sign Out
              </Button>
              <ThemeToggle />
              <Link
                href="/account"
                aria-label="My Account"
                className="ml-2 inline-flex rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
              >
                <UserAvatar user={user} size="sm" />
              </Link>
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
