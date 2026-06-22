import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/router";
import { CheckCircle2, CreditCard, Sparkles, Users } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { cn } from "@/lib/utils";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";

interface BillingStatus {
  plan: string;
  status: string | null;
  active: boolean;
  billingAvailable: boolean;
  seats: number | null;
  billingInterval: string | null;
  currentPeriodEnd: string | null;
  cancelAtPeriodEnd: boolean;
  isAdmin: boolean;
  limits: {
    freeSpaceMemberLimit: number;
    memberCount: number;
    freeMcpDailyLimit: number;
  };
}

type Interval = "month" | "year";

/**
 * "Plan & billing" card for the space settings page (#billing). Admin-only;
 * renders the current plan + free-tier usage and routes upgrade/manage through
 * Stripe-hosted Checkout / Customer Portal (we just follow the returned URL).
 * Not rendered for personal spaces (never billable). Degrades to an info note
 * when the instance isn't wired to Stripe yet.
 */
const SpaceBillingCard = ({ spaceId }: { spaceId: string }) => {
  const router = useRouter();
  const [status, setStatus] = useState<BillingStatus | null>(null);
  const [busy, setBusy] = useState(false);
  const [interval, setInterval] = useState<Interval>("month");
  const [error, setError] = useState<string | null>(null);

  // Banner after returning from Stripe Checkout (?billing=success|cancelled).
  const billingParam = router.query.billing;
  const checkoutOutcome =
    billingParam === "success" || billingParam === "cancelled"
      ? billingParam
      : null;

  const load = useCallback(async () => {
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/billing`,
        { credentials: "include", headers: { Accept: "application/json" } },
      );
      if (!res.ok) return;
      setStatus((await res.json()) as BillingStatus);
    } catch {
      // Non-fatal — the rest of settings still works without the card.
    }
  }, [spaceId]);

  useEffect(() => {
    void load();
  }, [load]);

  const post = async (action: "checkout" | "portal") => {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/billing/${action}`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(action === "checkout" ? { interval } : {}),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok || typeof data.url !== "string") {
        throw new Error(data.error || "Could not reach billing. Please try again.");
      }
      // Hand off to the Stripe-hosted page.
      window.location.href = data.url;
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
      setBusy(false);
    }
  };

  if (!status) return null;

  const isTeam = status.active;
  const memberCount = status.limits.memberCount;
  const memberLimit = status.limits.freeSpaceMemberLimit;
  const renews = status.currentPeriodEnd
    ? new Date(status.currentPeriodEnd).toLocaleDateString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
      })
    : null;

  return (
    <Card className="mb-6">
      <CardContent className="pt-6 space-y-4">
        <div className="flex items-center justify-between gap-2">
          <h2 className="font-semibold">Plan &amp; billing</h2>
          <Badge
            variant={isTeam ? "secondary" : "outline"}
            className={cn(
              "capitalize",
              isTeam && "bg-violet-100 text-violet-700 hover:bg-violet-100",
            )}
          >
            {isTeam ? "Team" : "Free"}
          </Badge>
        </div>

        {checkoutOutcome === "success" && (
          <Alert>
            <AlertDescription className="flex items-center gap-2">
              <CheckCircle2 className="h-4 w-4 text-emerald-600" aria-hidden />
              Thanks! Your subscription is being activated — it&apos;ll show here
              once Stripe confirms it.
            </AlertDescription>
          </Alert>
        )}
        {checkoutOutcome === "cancelled" && (
          <Alert>
            <AlertDescription>
              Checkout was cancelled — no charge was made.
            </AlertDescription>
          </Alert>
        )}
        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {isTeam ? (
          <div className="flex items-center justify-between gap-3 rounded-md border px-4 py-3">
            <div className="min-w-0">
              <p className="font-medium flex items-center gap-1.5">
                <Sparkles className="h-4 w-4 text-violet-600" aria-hidden />
                Team plan — unlimited members &amp; automation
              </p>
              <p className="text-sm text-muted-foreground">
                {status.cancelAtPeriodEnd
                  ? `Cancels${renews ? ` on ${renews}` : ""} — access continues until then.`
                  : renews
                    ? `Renews ${renews}${status.seats ? ` · ${status.seats} seats` : ""}.`
                    : "Active."}
                {status.status === "past_due" &&
                  " Payment is past due — update your card to avoid losing access."}
              </p>
            </div>
            <Button
              variant="outline"
              size="sm"
              className="gap-1.5 shrink-0"
              onClick={() => void post("portal")}
              disabled={busy}
            >
              <CreditCard className="h-4 w-4" aria-hidden />
              Manage billing
            </Button>
          </div>
        ) : (
          <>
            <div className="flex items-center gap-2 rounded-md border px-4 py-3 text-sm">
              <Users className="h-4 w-4 text-muted-foreground" aria-hidden />
              <span>
                <span className="font-medium">
                  {memberCount} / {memberLimit}
                </span>{" "}
                <span className="text-muted-foreground">
                  free members used · {status.limits.freeMcpDailyLimit} MCP
                  calls/day on free
                </span>
              </span>
            </div>

            {status.billingAvailable ? (
              <div className="rounded-md border px-4 py-3 space-y-3">
                <div>
                  <p className="font-medium flex items-center gap-1.5">
                    <Sparkles className="h-4 w-4 text-violet-600" aria-hidden />
                    Upgrade to Team
                  </p>
                  <p className="text-sm text-muted-foreground">
                    Unlimited members, shared spaces, and MCP/API throughput for
                    everyone in this space.
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <div
                    className="inline-flex rounded-md border p-0.5"
                    role="group"
                    aria-label="Billing interval"
                  >
                    {(["month", "year"] as const).map((opt) => (
                      <button
                        key={opt}
                        type="button"
                        onClick={() => setInterval(opt)}
                        className={cn(
                          "rounded px-2.5 py-1 text-xs font-medium capitalize",
                          interval === opt
                            ? "bg-violet-600 text-white"
                            : "text-muted-foreground hover:text-foreground",
                        )}
                      >
                        {opt === "month" ? "Monthly" : "Yearly"}
                      </button>
                    ))}
                  </div>
                  <Button
                    size="sm"
                    className="gap-1.5 bg-violet-600 hover:bg-violet-500 text-white"
                    onClick={() => void post("checkout")}
                    disabled={busy}
                  >
                    <Sparkles className="h-4 w-4" aria-hidden />
                    {busy ? "Starting…" : "Upgrade to Team"}
                  </Button>
                </div>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">
                Paid plans aren&apos;t enabled on this instance yet.
              </p>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
};

export default SpaceBillingCard;
