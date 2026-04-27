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
    <nav className="bg-cyan-700 text-white">
      <div className="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
        <Link href="/" className="text-white font-bold text-lg no-underline">
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
                    "text-cyan-200 hover:bg-cyan-800 hover:text-white",
                    router.pathname.startsWith("/admin") && "text-white",
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
                    className={cn(
                      "text-cyan-200 hover:bg-cyan-800 hover:text-white",
                      active && "text-white",
                    )}
                  >
                    <Link href={link.href}>{link.label}</Link>
                  </Button>
                );
              })}
              <Button
                variant="ghost"
                size="sm"
                onClick={logout}
                className="text-cyan-200 hover:bg-cyan-800 hover:text-white"
              >
                Sign Out
              </Button>
              <ThemeToggle />
              <Link
                href="/account"
                aria-label="My Account"
                className="ml-2 inline-flex rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-cyan-700"
              >
                <UserAvatar user={user} size="sm" />
              </Link>
            </>
          ) : (
            <>
              <Button
                asChild
                variant="ghost"
                size="sm"
                className="text-cyan-200 hover:bg-cyan-800 hover:text-white"
              >
                <Link href="/signin">Sign In</Link>
              </Button>
              <Button asChild variant="secondary" size="sm" className="bg-white text-cyan-700 hover:bg-cyan-100">
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
