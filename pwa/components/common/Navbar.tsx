import Link from "next/link";
import { useRouter } from "next/router";
import { LogOut, ShieldCheck, User as UserIcon } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import UserAvatar from "@/components/user/UserAvatar";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";

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

  const handleLogout = () => {
    logout();
    router.push("/");
  };

  return (
    <nav className="bg-cyan-700 text-white">
      <div className="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
        <Link href="/" className="text-white font-bold text-lg no-underline">
          Aura
        </Link>

        <div className="flex items-center gap-1">
          {isAuthenticated && user ? (
            <>
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
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <button
                    type="button"
                    aria-label="My Account"
                    className="ml-2 inline-flex rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-cyan-700"
                  >
                    <UserAvatar user={user} size="sm" />
                  </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48">
                  <DropdownMenuLabel className="truncate">{user.email}</DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem asChild>
                    <Link href="/account">
                      <UserIcon className="mr-2 h-4 w-4" />
                      My Account
                    </Link>
                  </DropdownMenuItem>
                  {isAdmin && (
                    <DropdownMenuItem asChild>
                      <Link href="/admin">
                        <ShieldCheck className="mr-2 h-4 w-4" />
                        Admin
                      </Link>
                    </DropdownMenuItem>
                  )}
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onSelect={handleLogout} className="text-red-600 focus:text-red-700">
                    <LogOut className="mr-2 h-4 w-4" />
                    Sign Out
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
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
            </>
          )}
        </div>
      </div>
    </nav>
  );
};

export default Navbar;
