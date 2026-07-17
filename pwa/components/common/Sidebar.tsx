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
 * Sits in normal document flow and stretches to the row height (the parent
 * layout row is `min-h-screen`), so the border + background always run the
 * full page height. It grows with its own content rather than scrolling
 * internally — a nav taller than the viewport makes the whole page grow and
 * the document scroll as one (no separate sidebar scrollbar).
 */
const Sidebar = () => {
  const { isAuthenticated } = useAuth();
  if (!isAuthenticated) return null;
  return (
    <aside
      className="hidden md:flex w-64 shrink-0 border-r bg-background flex-col"
      data-testid="app-sidebar"
    >
      <SidebarNav includeSpaceSwitcher />
    </aside>
  );
};

export default Sidebar;
