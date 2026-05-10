import { useAuth } from "@/contexts/AuthContext";
import SidebarNav from "./SidebarNav";

/**
 * Persistent left-side navigation. Visible at `md` and up; on smaller
 * viewports the same nav surface lives inside the navbar's mobile
 * Sheet (so we don't eat half the screen on phones).
 *
 * Renders nothing when the user isn't signed in — the public-facing
 * marketing/auth screens keep the original full-width chrome.
 */
const Sidebar = () => {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) return null;
  return (
    <aside
      className="hidden md:flex sticky top-0 h-screen w-60 shrink-0 border-r bg-background flex-col"
      data-testid="app-sidebar"
    >
      <SidebarNav />
    </aside>
  );
};

export default Sidebar;
