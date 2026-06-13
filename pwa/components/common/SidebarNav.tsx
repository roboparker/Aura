import Link from "next/link";
import { useRouter } from "next/router";
import type { ReactNode } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import SpaceSwitcher from "./SpaceSwitcher";

// The account menu (avatar + personal links + sign out + stop
// impersonation) and the notification bell live in the top bar now —
// see UserMenu / Navbar. The sidebar carries the space switcher and the
// admin section.

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
 * navbar. Single source of truth so the space switcher and admin
 * section stay in sync across both surfaces.
 */
const SidebarNav = ({ itemWrapper }: SidebarNavProps) => {
  const { user, isAuthenticated } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");
  const wrap = itemWrapper ?? ((c) => c);

  if (!isAuthenticated || !user) return null;

  return (
    <div className="flex h-full flex-col">
      <div className="px-2 pt-4 pb-2">
        <SpaceSwitcher />
      </div>

      <nav className="flex flex-col gap-0.5 px-2 pb-4 flex-1 overflow-y-auto">
        {isAdmin && (
          <>
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
    </div>
  );
};

export default SidebarNav;
