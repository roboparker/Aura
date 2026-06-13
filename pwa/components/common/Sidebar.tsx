import { useAuth } from "@/contexts/AuthContext";
import { cn } from "@/lib/utils";
import SidebarNav from "./SidebarNav";

/**
 * Persistent left-side navigation. Visible at `md` and up; on smaller
 * viewports the same nav surface lives inside the navbar's mobile
 * Sheet (so we don't eat half the screen on phones).
 *
 * Renders nothing when the user isn't signed in — the public-facing
 * marketing/auth screens keep the original full-width chrome.
 *
 * Sticks below the sticky header. The header is the 3.5rem top bar,
 * plus the 2.75rem ImpersonationBanner when an admin is impersonating
 * (md+ banner height is fixed so this offset stays flush) — hence the
 * 6.25rem combined offset in that case.
 */
const Sidebar = () => {
  const { isAuthenticated, user } = useAuth();
  if (!isAuthenticated) return null;
  const impersonating = Boolean(user?.impersonator);
  return (
    <aside
      className={cn(
        "hidden md:flex w-60 shrink-0 border-r bg-background flex-col sticky",
        impersonating
          ? "top-[6.25rem] h-[calc(100vh-6.25rem)]"
          : "top-14 h-[calc(100vh-3.5rem)]",
      )}
      data-testid="app-sidebar"
    >
      <SidebarNav />
    </aside>
  );
};

export default Sidebar;
