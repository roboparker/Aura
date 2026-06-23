import { useEffect, useState } from "react";
import { Gauge, Infinity as InfinityIcon } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { cn } from "@/lib/utils";

interface Usage {
  mcpCallsToday: number;
  freeMcpDailyLimit: number;
  remaining: number | null;
  entitled: boolean;
  admin: boolean;
  enforcementEnabled: boolean;
}

/**
 * Compact "MCP usage today" panel for the API tokens settings page
 * (#billing). Shows today's metered MCP calls against the free daily cap, or
 * an "unlimited" state for users on a paid Team space. When billing
 * enforcement is still dark, the cap is framed as a preview.
 */
const UsageSummary = () => {
  const [usage, setUsage] = useState<Usage | null>(null);

  useEffect(() => {
    let active = true;
    void (async () => {
      try {
        const res = await fetch(`${ENTRYPOINT}/me/usage`, {
          credentials: "include",
          headers: { Accept: "application/json" },
        });
        if (!res.ok) return;
        const data = (await res.json()) as Usage;
        if (active) setUsage(data);
      } catch {
        // Non-fatal — the tokens table renders fine without this panel.
      }
    })();
    return () => {
      active = false;
    };
  }, []);

  if (!usage) return null;

  // Admins are uncapped but we still surface today's count so their usage
  // stays visible; entitled (Team) members get the plain "Unlimited" state.
  const adminUncapped = usage.admin && !usage.entitled;
  const unlimited = usage.entitled || usage.admin;
  const used = usage.mcpCallsToday;
  const limit = usage.freeMcpDailyLimit;
  const pct = unlimited ? 0 : Math.min(100, Math.round((used / Math.max(1, limit)) * 100));
  const atLimit = !unlimited && usage.remaining !== null && usage.remaining <= 0;

  return (
    <div className="rounded-md border px-4 py-3">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm font-medium flex items-center gap-1.5">
          <Gauge className="h-4 w-4 text-muted-foreground" aria-hidden />
          MCP usage today
        </p>
        {adminUncapped ? (
          <span className="inline-flex items-center gap-1.5 text-sm font-medium">
            <span className="tabular-nums text-foreground">{used} today</span>
            <span className="inline-flex items-center gap-1 text-violet-600">
              <InfinityIcon className="h-4 w-4" aria-hidden />
              No limit
            </span>
          </span>
        ) : unlimited ? (
          <span className="inline-flex items-center gap-1 text-sm font-medium text-violet-600">
            <InfinityIcon className="h-4 w-4" aria-hidden />
            Unlimited
          </span>
        ) : (
          <span
            className={cn(
              "text-sm font-medium tabular-nums",
              atLimit ? "text-destructive" : "text-foreground",
            )}
          >
            {used} / {limit}
          </span>
        )}
      </div>

      {!unlimited && (
        <>
          <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted">
            <div
              className={cn(
                "h-full rounded-full transition-all",
                atLimit ? "bg-destructive" : "bg-violet-500",
              )}
              style={{ width: `${pct}%` }}
            />
          </div>
          <p className="mt-2 text-xs text-muted-foreground">
            {usage.enforcementEnabled
              ? "Free plan: resets daily (UTC). Upgrade a space to Team for unlimited automation."
              : "Preview — usage isn't capped yet. The free plan will allow this many MCP calls per day."}
          </p>
        </>
      )}
      {unlimited && (
        <p className="mt-2 text-xs text-muted-foreground">
          {usage.entitled
            ? "You're on a Team space — unlimited MCP & API throughput."
            : "Admin account — no MCP cap. Today's calls are still tracked above."}
        </p>
      )}
    </div>
  );
};

export default UsageSummary;
