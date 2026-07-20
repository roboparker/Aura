import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/router";
import { Banknote, CheckCircle2, Clock, ExternalLink } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { cn } from "@/lib/utils";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";

interface ConnectStatus {
  status: "none" | "incomplete" | "pending" | "ready";
  chargesEnabled: boolean;
  detailsSubmitted: boolean;
  available: boolean;
  isAdmin: boolean;
}

/**
 * "Payments (get paid)" card for the space settings page (#connect). Lets a
 * space admin set up a Stripe Connect account so client invoice payments land
 * in their own account instead of the platform. Onboarding is Stripe-hosted —
 * we just follow the Account Link URL. Not rendered for personal spaces.
 * Degrades to an info note when the instance isn't wired to Stripe.
 */
const SpaceConnectCard = ({ spaceId }: { spaceId: string }) => {
  const router = useRouter();
  const [status, setStatus] = useState<ConnectStatus | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Banner after returning from Stripe onboarding (?connect=return|refresh).
  const connectParam = router.query.connect;
  const returnOutcome =
    connectParam === "return" || connectParam === "refresh" ? connectParam : null;

  const load = useCallback(async () => {
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/connect/status`,
        { credentials: "include", headers: { Accept: "application/json" } },
      );
      if (!res.ok) return;
      setStatus((await res.json()) as ConnectStatus);
    } catch {
      // Non-fatal — the rest of settings still works without the card.
    }
  }, [spaceId]);

  useEffect(() => {
    void load();
  }, [load]);

  const onboard = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/connect/onboard`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: "{}",
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok || typeof data.url !== "string") {
        throw new Error(data.error || "Could not start setup. Please try again.");
      }
      window.location.href = data.url;
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
      setBusy(false);
    }
  };

  if (!status) return null;

  const ready = status.status === "ready";
  const pending = status.status === "pending";
  const cta = status.status === "none" ? "Set up payments" : "Continue setup";

  return (
    <Card className="mb-6">
      <CardContent className="pt-6 space-y-4">
        <div className="flex items-center justify-between gap-2">
          <h2 className="font-semibold flex items-center gap-1.5">
            <Banknote className="h-4 w-4 text-emerald-600" aria-hidden />
            Payments
          </h2>
          <Badge
            variant={ready ? "secondary" : "outline"}
            className={cn(
              ready && "bg-emerald-100 text-emerald-700 hover:bg-emerald-100",
              pending && "bg-amber-100 text-amber-700 hover:bg-amber-100",
            )}
          >
            {ready ? "Active" : pending ? "Pending review" : "Not set up"}
          </Badge>
        </div>

        <p className="text-sm text-muted-foreground">
          Connect a Stripe account so clients can pay your invoices online — the
          money lands directly in your account.
        </p>

        {returnOutcome === "return" && !ready && (
          <Alert>
            <AlertDescription className="flex items-center gap-2">
              <Clock className="h-4 w-4 text-amber-600" aria-hidden />
              Thanks — Stripe is reviewing your details. This card updates once
              you&apos;re approved.
            </AlertDescription>
          </Alert>
        )}
        {returnOutcome === "refresh" && (
          <Alert>
            <AlertDescription>
              That setup link expired. Start again below.
            </AlertDescription>
          </Alert>
        )}
        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {!status.available ? (
          <p className="text-sm text-muted-foreground">
            Online payments aren&apos;t enabled on this instance yet.
          </p>
        ) : ready ? (
          <div className="flex items-center justify-between gap-3 rounded-md border px-4 py-3">
            <p className="text-sm font-medium flex items-center gap-1.5">
              <CheckCircle2 className="h-4 w-4 text-emerald-600" aria-hidden />
              Client payments are enabled.
            </p>
            {status.isAdmin && (
              <Button
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={() => void onboard()}
                disabled={busy}
              >
                <ExternalLink className="h-4 w-4" aria-hidden />
                Update details
              </Button>
            )}
          </div>
        ) : status.isAdmin ? (
          <Button
            size="sm"
            className="gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white"
            onClick={() => void onboard()}
            disabled={busy}
          >
            <Banknote className="h-4 w-4" aria-hidden />
            {busy ? "Starting…" : cta}
          </Button>
        ) : (
          <p className="text-sm text-muted-foreground">
            A space admin hasn&apos;t set up online payments yet.
          </p>
        )}
      </CardContent>
    </Card>
  );
};

export default SpaceConnectCard;
