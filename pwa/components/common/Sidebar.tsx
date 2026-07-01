import { useAuth } from "@/contexts/AuthContext";
import SidebarNav from "./SidebarNav";

/**
 * Persistent left-side navigation. Visible at `md` and up; on smaller
 * viewports the same nav surface lives inside the navbar's mobile
 * Sheet (so we don't eat half the screen on phones).
 *
 * Renders nothing when the user isn't signed in — the public-facing
 * marketing/auth screens keep the original full-width chrome.
 *
 * Full viewport height, pinned to the left edge (`sticky top-0 h-screen`):
 * the header (banner + navbar) lives in the content column to its right, so
 * the space switcher at the top of the nav sits above everything. Its own
 * content scrolls internally (SidebarNav's `overflow-y-auto`).
 */
const Sidebar = () => {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) return null;
  return (
    <aside
      className="hidden md:flex w-60 shrink-0 border-r bg-background flex-col sticky top-0 h-screen"
      data-testid="app-sidebar"
    >
      <SidebarNav includeSpaceSwitcher />
    </aside>
  );
};

export default Sidebar;
