import { useEffect, useState } from "react";
import { Sparkles } from "lucide-react";
import Link from "next/link";
import { ENTRYPOINT } from "@/config/entrypoint";
import {
  creditUsagePercent,
  type AiCreditBalance,
} from "@/lib/agentTypes";
import { cn } from "@/lib/utils";
import { Alert, AlertDescription } from "@/components/ui/alert";

/**
 * The account's AI allowance for the month (#827), shown above the agent list
 * because "how much is left" is the question you have right before you give an
 * agent something to do.
 *
 * Two states worth distinguishing, and the reason `unavailableReason` is a key
 * rather than a sentence: **plan_not_entitled** is a sales moment and links to
 * the upgrade, while **provider_not_configured** is an operator problem the
 * viewer can do nothing about. Showing an upgrade button for the latter would
 * charge someone for a fix that isn't theirs to make.
 */
const AiCreditsMeter = ({ spaceId }: { spaceId: string }) => {
  const [balance, setBalance] = useState<AiCreditBalance | null>(null);

  useEffect(() => {
    let cancelled = false;
    const load = async () => {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/ai-credits`,
        { credentials: "include", headers: { Accept: "application/json" } },
      );
      if (!res.ok || cancelled) return;
      const data: AiCreditBalance = await res.json();
      if (!cancelled) setBalance(data);
    };
    void load();
    return () => {
      cancelled = true;
    };
  }, [spaceId]);

  if (!balance) return null;

  const percent = creditUsagePercent(balance);
  const needsUpgrade = balance.unavailableReason === "plan_not_entitled";

  return (
    <div className="rounded-md border px-3 py-2.5 space-y-2">
      <div className="flex items-center justify-between gap-2 text-sm">
        <span className="flex items-center gap-1.5 font-medium">
          <Sparkles className="h-3.5 w-3.5 text-muted-foreground" aria-hidden />
          AI credits
        </span>
        <span className="text-muted-foreground">
          {balance.unlimited
            ? `${balance.usedCredits.toLocaleString()} used · unlimited`
            : `${balance.usedCredits.toLocaleString()} of ${(balance.allowanceCredits ?? 0).toLocaleString()}`}
        </span>
      </div>

      {!balance.unlimited && (balance.allowanceCredits ?? 0) > 0 && (
        <div
          className="h-1.5 w-full overflow-hidden rounded-full bg-muted"
          role="progressbar"
          aria-valuenow={percent}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label="AI credits used this month"
        >
          <div
            className={cn(
              "h-full rounded-full transition-all",
              // A semantic scale: the colour is the warning, not decoration.
              percent >= 100
                ? "bg-red-500"
                : percent >= 80
                  ? "bg-amber-500"
                  : "bg-emerald-500",
            )}
            style={{ width: `${Math.max(percent, 2)}%` }}
          />
        </div>
      )}

      <p className="text-xs text-muted-foreground">
        Shared across {balance.organization.name} and resets at the start of
        each month.
      </p>

      {balance.unavailableMessage && (
        <Alert variant={needsUpgrade ? "default" : "destructive"} className="py-2">
          <AlertDescription className="text-xs">
            {balance.unavailableMessage}
            {needsUpgrade && (
              <>
                {" "}
                <Link href="/pricing" className="text-primary hover:underline">
                  See plans
                </Link>
              </>
            )}
          </AlertDescription>
        </Alert>
      )}
    </div>
  );
};

export default AiCreditsMeter;
