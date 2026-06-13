import Link from "next/link";
import { useRouter } from "next/router";
import type { ReactNode } from "react";
import { LogOut, VenetianMask } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { displayName } from "@/lib/userDisplay";
import UserAvatar from "@/components/user/UserAvatar";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { cn } from "@/lib/utils";
import SpaceSwitcher from "./SpaceSwitcher";

// Personal section — things scoped to the signed-in user themselves.
// Lives in its own block at the top of the sidebar with a divider
// from the shared/space-scoped nav below. Sign Out lives outside this
// list — it's an action (handler) rather than a destination, so it
// stays as a button at the bottom of the sidebar.
const PERSONAL_NAV_LINKS = [
  { href: "/my-tasks", label: "My Tasks" },
  { href: "/notifications", label: "Notifications" },
  { href: "/groups", label: "Groups" },
  { href: "/settings/profile", label: "Settings" },
];

// The shared section is now a list of accessible spaces (rendered
// from ActiveSpaceContext at render time, since it's per-user state).
// "All Spaces" is the only static entry — it's the management
// surface; each space below is a link to its tabbed detail page
// (#nav-refresh) which is itself the hub for projects, discussions,
// pages, and tasks inside that space.

// Backend tooling surfaced inside the PWA chrome. The Mercure debugger
// is served by Caddy (FrankenPHP), not Next.js, so it's a regular <a>
// link rather than a <Link>. Admin-only.
//
// The debugger UI only exists when Caddy is started with the `demo`
// Mercure directive (MERCURE_EXTRA_DIRECTIVES=demo), which we set only
// in dev + e2e. Production leaves it unset, so /.well-known/mercure/ui/
// 404s there — hence we omit the link from production builds entirely
// rather than surface a dead admin link.
const ADMIN_EXTERNAL_LINKS =
  process.env.NODE_ENV === "production"
    ? []
    : [{ href: "/.well-known/mercure/ui/", label: "Mercure" }];

interface SidebarNavProps {
  /**
   * Wrap each interactive item — used by the mobile Sheet variant to
   * auto-close on selection. The persistent sidebar passes through.
   */
  itemWrapper?: (children: ReactNode) => ReactNode;
}

/**
 * Shared navigation contents used by both the persistent left-side
 * `<Sidebar>` (`md:` and up) and the mobile-only Sheet variant in the
 * navbar. Single source of truth so the link list, admin section, and
 * account quick-actions stay in sync across both surfaces.
 */
const SidebarNav = ({ itemWrapper }: SidebarNavProps) => {
  const { user, isAuthenticated, logout, stopImpersonation } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");
  const impersonator = user?.impersonator ?? null;
  const wrap = itemWrapper ?? ((c) => c);

  const handleStopImpersonation = () => {
    void stopImpersonation().then(() => router.push("/"));
  };

  if (!isAuthenticated || !user) return null;

  return (
    <div className="flex h-full flex-col">
      <div
        className="flex items-start gap-3 px-4 pt-4 pb-3"
        data-testid="sidebar-user-header"
      >
        <UserAvatar user={user} size="sm" />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold truncate">{displayName(user)}</p>
          <p className="text-xs text-muted-foreground truncate">{user.email}</p>
        </div>
        <Button
          variant="ghost"
          size="icon"
          onClick={logout}
          aria-label="Sign out"
          title="Sign out"
          className="h-8 w-8 shrink-0 text-muted-foreground hover:text-destructive"
        >
          <LogOut className="h-4 w-4" />
        </Button>
      </div>

      <Separator className="my-2" />

      <div className="px-2 pb-2">
        <SpaceSwitcher />
      </div>

      <nav className="flex flex-col gap-0.5 px-2 pb-4 flex-1 overflow-y-auto">
        {PERSONAL_NAV_LINKS.map((link) => {
          const active = router.pathname.startsWith(link.href);
          return (
            <span key={link.href}>
              {wrap(
                <Button
                  asChild
                  variant="ghost"
                  size="sm"
                  className={cn(
                    "justify-start w-full",
                    active && "bg-accent text-accent-foreground",
                  )}
                >
                  <Link href={link.href}>{link.label}</Link>
                </Button>,
              )}
            </span>
          );
        })}

        {isAdmin && (
          <>
            <Separator className="my-2" />
            <p className="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Admin
            </p>
            <span>
              {wrap(
                <Button
                  asChild
                  variant="ghost"
                  size="sm"
                  className={cn(
                    "justify-start w-full",
                    router.pathname.startsWith("/admin/users") &&
                      "bg-accent text-accent-foreground",
                  )}
                >
                  <Link href="/admin/users">Users</Link>
                </Button>,
              )}
            </span>
            <span>
              {wrap(
                <Button
                  asChild
                  variant="ghost"
                  size="sm"
                  className={cn(
                    "justify-start w-full",
                    router.pathname.startsWith("/admin/waitlist") &&
                      "bg-accent text-accent-foreground",
                  )}
                >
                  <Link href="/admin/waitlist">Waitlist</Link>
                </Button>,
              )}
            </span>
            <span>
              {wrap(
                <Button
                  asChild
                  variant="ghost"
                  size="sm"
                  className={cn(
                    "justify-start w-full",
                    router.pathname.startsWith("/admin/segments") &&
                      "bg-accent text-accent-foreground",
                  )}
                >
                  <Link href="/admin/segments">Segments</Link>
                </Button>,
              )}
            </span>
            {ADMIN_EXTERNAL_LINKS.map(({ href, label }) => (
              <Button
                key={href}
                asChild
                variant="ghost"
                size="sm"
                className="justify-start w-full"
              >
                <a href={href} target="_blank" rel="noopener noreferrer">
                  {label}
                </a>
              </Button>
            ))}
          </>
        )}
      </nav>

      {/* While impersonating, a prominent way out lives at the bottom of
          the side menu (mirrors the global top banner). Only the firewall's
          switch_user exit restores the admin's own session. */}
      {impersonator && (
        <div className="border-t border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-900 dark:bg-amber-950/40">
          <p className="px-1 pb-2 text-xs text-amber-800 dark:text-amber-200">
            Impersonating{" "}
            <span className="font-semibold">{displayName(user)}</span>
          </p>
          {wrap(
            <Button
              variant="outline"
              size="sm"
              onClick={handleStopImpersonation}
              className="w-full border-amber-400 text-amber-900 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-100 dark:hover:bg-amber-900/40"
              data-testid="stop-impersonation"
            >
              <VenetianMask className="mr-1.5 h-4 w-4" />
              Stop impersonation
            </Button>,
          )}
        </div>
      )}

      {/* Sign Out is an action (handler), not a destination — keep it
          as a button anchored to the bottom of the sidebar so it
          doesn't blend into the navigation links above. */}
      <div className="border-t px-3 py-2">
        <Button
          variant="outline"
          size="sm"
          onClick={logout}
          className="w-full"
        >
          Sign Out
        </Button>
      </div>
    </div>
  );
};

export default SidebarNav;
