import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/router";
import { CheckCircle2, CreditCard, Loader2, Sparkles } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { cn } from "@/lib/utils";
import CancellationSurvey from "@/components/feedback/CancellationSurvey";
import { cancellationFeedbackBody } from "@/lib/cancellationReasons";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";

interface AccountBillingStatus {
  plan: string;
  planLabel: string;
  status: string | null;
  active: boolean;
  billingAvailable: boolean;
  seats?: number | null;
  seatCount?: number | null;
  billingInterval: string | null;
  currentPeriodEnd: string | null;
  cancelAtPeriodEnd: boolean;
  isAdmin?: boolean;
}

type Interval = "month" | "year";

interface Props {
  /** API base for the account, e.g. `/me/billing` or `/organizations/{id}/billing`. */
  endpointBase: string;
  /** The plan a free account can self-upgrade to (label + blurb). */
  upgradeLabel: string;
  upgradeBlurb: string;
  /** Shown under a paid Business org — Enterprise is sales-led. */
  enterpriseNote?: boolean;
}

/**
 * Plan & billing card shared by the personal ({@link /settings/billing}) and
 * organization ({@link /organizations/[id]/settings}) surfaces. Reads the
 * account's billing status and routes upgrade/manage/cancel through the
 * Stripe-hosted Checkout / Customer Portal (we just follow the returned URL).
 * Mirrors the space billing card; the billable unit is the account.
 */
const AccountBillingCard = ({ endpointBase, upgradeLabel, upgradeBlurb, enterpriseNote }: Props) => {
  const router = useRouter();
  const [status, setStatus] = useState<AccountBillingStatus | null>(null);
  const [busy, setBusy] = useState(false);
  const [interval, setInterval] = useState<Interval>("month");
  const [error, setError] = useState<string | null>(null);

  const [cancelOpen, setCancelOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [comment, setComment] = useState("");
  const [cancelBusy, setCancelBusy] = useState(false);
  const [cancelError, setCancelError] = useState<string | null>(null);

  const billingParam = router.query.billing;
  const checkoutOutcome =
    billingParam === "success" || billingParam === "cancelled" ? billingParam : null;

  const load = useCallback(async () => {
    try {
      const res = await fetch(`${ENTRYPOINT}${endpointBase}`, {
        credentials: "include",
        headers: { Accept: "application/json" },
      });
      if (!res.ok) return;
      setStatus((await res.json()) as AccountBillingStatus);
    } catch {
      // Non-fatal — the rest of the page still works without the card.
    }
  }, [endpointBase]);

  useEffect(() => {
    void load();
  }, [load]);

  const post = async (action: "checkout" | "portal") => {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${endpointBase}/${action}`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(action === "checkout" ? { interval } : {}),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || typeof data.url !== "string") {
        throw new Error(data.error || "Could not reach billing. Please try again.");
      }
      window.location.href = data.url;
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
      setBusy(false);
    }
  };

  const feedback = cancellationFeedbackBody(reason, comment);

  const closeCancel = () => {
    setCancelOpen(false);
    setReason("");
    setComment("");
    setCancelError(null);
  };

  const submitCancel = async () => {
    if (!feedback) return;
    setCancelBusy(true);
    setCancelError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${endpointBase}/cancel`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(feedback),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.error || "Could not cancel. Please try again.");
      }
      closeCancel();
      await load();
    } catch (err) {
      setCancelError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setCancelBusy(false);
    }
  };

  if (!status) return null;

  const isPaid = status.active;
  const canManage = status.isAdmin !== false;
  const renews = status.currentPeriodEnd
    ? new Date(status.currentPeriodEnd).toLocaleDateString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
      })
    : null;
  const seats = status.seats ?? status.seatCount ?? null;

  return (
    <Card className="mb-6">
      <CardContent className="pt-6 space-y-4">
        <div className="flex items-center justify-between gap-2">
          <h2 className="font-semibold">Plan &amp; billing</h2>
          <Badge
            variant={isPaid ? "secondary" : "outline"}
            className={cn(isPaid && "bg-violet-100 text-violet-700 hover:bg-violet-100")}
          >
            {status.planLabel}
          </Badge>
        </div>

        {checkoutOutcome === "success" && (
          <Alert>
            <AlertDescription className="flex items-center gap-2">
              <CheckCircle2 className="h-4 w-4 text-emerald-600" aria-hidden />
              Thanks! Your subscription is being activated — it&apos;ll show here once
              Stripe confirms it.
            </AlertDescription>
          </Alert>
        )}
        {checkoutOutcome === "cancelled" && (
          <Alert>
            <AlertDescription>Checkout was cancelled — no charge was made.</AlertDescription>
          </Alert>
        )}
        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {isPaid ? (
          <div className="flex items-center justify-between gap-3 rounded-md border px-4 py-3">
            <div className="min-w-0">
              <p className="font-medium flex items-center gap-1.5">
                <Sparkles className="h-4 w-4 text-violet-600" aria-hidden />
                {status.planLabel} plan
              </p>
              <p className="text-sm text-muted-foreground">
                {status.cancelAtPeriodEnd
                  ? `Cancels${renews ? ` on ${renews}` : ""} — access continues until then.`
                  : renews
                    ? `Renews ${renews}${seats ? ` · ${seats} seats` : ""}.`
                    : "Active."}
                {status.status === "past_due" &&
                  " Payment is past due — update your card to avoid losing access."}
              </p>
            </div>
            {canManage && (
              <div className="flex shrink-0 items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  className="gap-1.5"
                  onClick={() => void post("portal")}
                  disabled={busy}
                >
                  <CreditCard className="h-4 w-4" aria-hidden />
                  Manage billing
                </Button>
                {!status.cancelAtPeriodEnd && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground hover:text-destructive"
                    onClick={() => setCancelOpen(true)}
                    disabled={busy}
                  >
                    Cancel
                  </Button>
                )}
              </div>
            )}
          </div>
        ) : (
          <>
            {status.billingAvailable && canManage ? (
              <div className="rounded-md border px-4 py-3 space-y-3">
                <div>
                  <p className="font-medium flex items-center gap-1.5">
                    <Sparkles className="h-4 w-4 text-violet-600" aria-hidden />
                    Upgrade to {upgradeLabel}
                  </p>
                  <p className="text-sm text-muted-foreground">{upgradeBlurb}</p>
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
                          "rounded px-2.5 py-1 text-xs font-medium",
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
                    {busy ? "Starting…" : `Upgrade to ${upgradeLabel}`}
                  </Button>
                </div>
              </div>
            ) : !status.billingAvailable ? (
              <p className="text-sm text-muted-foreground">
                Paid plans aren&apos;t enabled on this instance yet.
              </p>
            ) : (
              <p className="text-sm text-muted-foreground">
                You&apos;re on the {status.planLabel} plan.
              </p>
            )}
            {enterpriseNote && (
              <p className="text-xs text-muted-foreground">
                Need SSO, SCIM, or a custom contract?{" "}
                <a href="mailto:sales@madori.app" className="underline hover:text-foreground">
                  Talk to us about Enterprise
                </a>
                .
              </p>
            )}
          </>
        )}
      </CardContent>

      <Dialog open={cancelOpen} onOpenChange={(o: boolean) => (o ? setCancelOpen(true) : closeCancel())}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancel subscription</DialogTitle>
            <DialogDescription>
              Your plan stays active until the end of the current billing period
              {renews ? ` (${renews})` : ""} — you won&apos;t be charged again.
            </DialogDescription>
          </DialogHeader>
          {cancelError && (
            <Alert variant="destructive">
              <AlertDescription>{cancelError}</AlertDescription>
            </Alert>
          )}
          <CancellationSurvey
            idPrefix="sub-cancel"
            reason={reason}
            comment={comment}
            onReasonChange={setReason}
            onCommentChange={setComment}
            disabled={cancelBusy}
          />
          <DialogFooter>
            <Button type="button" variant="outline" onClick={closeCancel} disabled={cancelBusy}>
              Keep subscription
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={() => void submitCancel()}
              disabled={cancelBusy || !feedback}
              data-testid="sub-cancel-confirm"
            >
              {cancelBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : "Cancel subscription"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
};

export default AccountBillingCard;
