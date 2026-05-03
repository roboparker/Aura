import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { AlertTriangle } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { cn } from "@/lib/utils";

const POLL_INTERVAL_MS = 30_000;

interface OverdueBadgeProps {
  /** Hides the badge and pauses polling when the user isn't logged in, so we
   *  don't spam 401s on the public landing page. Mirrors NotificationBell. */
  enabled: boolean;
}

interface OverdueCollection {
  totalItems?: number;
}

const OverdueBadge = ({ enabled }: OverdueBadgeProps) => {
  const [count, setCount] = useState(0);

  const fetchCount = useCallback(async () => {
    if (!enabled) return;
    try {
      const res = await fetch(
        // itemsPerPage=1 keeps the response tiny — we only read totalItems.
        // The server-side overdue filter does the date math so the chrome
        // doesn't have to enumerate the user's tasks just to compute a badge.
        `${ENTRYPOINT}/tasks?overdue=true&itemsPerPage=1`,
        {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        },
      );
      if (!res.ok) return;
      const data: OverdueCollection = await res.json();
      setCount(typeof data.totalItems === "number" ? data.totalItems : 0);
    } catch {
      // Polling is a chrome convenience — stay silent on failure and let the
      // next tick recover.
    }
  }, [enabled]);

  useEffect(() => {
    if (!enabled) {
      setCount(0);
      return;
    }
    fetchCount();
    const id = window.setInterval(fetchCount, POLL_INTERVAL_MS);
    return () => window.clearInterval(id);
  }, [enabled, fetchCount]);

  // Refresh when the tab regains focus so a long idle doesn't leave a stale
  // count sitting in the navbar.
  useEffect(() => {
    if (!enabled) return;
    const onVisible = () => {
      if (document.visibilityState === "visible") fetchCount();
    };
    document.addEventListener("visibilitychange", onVisible);
    return () => document.removeEventListener("visibilitychange", onVisible);
  }, [enabled, fetchCount]);

  if (!enabled || count <= 0) return null;

  const countLabel = count > 9 ? "9+" : String(count);
  const ariaLabel = `${count} overdue task${count === 1 ? "" : "s"}`;

  return (
    <Link
      href="/tasks"
      aria-label={ariaLabel}
      title={ariaLabel}
      data-testid="navbar-overdue-badge"
      className={cn(
        "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold",
        "bg-destructive text-destructive-foreground no-underline",
        "hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
      )}
    >
      <AlertTriangle className="h-3 w-3" aria-hidden="true" />
      <span data-testid="navbar-overdue-count">{countLabel}</span>
    </Link>
  );
};

export default OverdueBadge;
