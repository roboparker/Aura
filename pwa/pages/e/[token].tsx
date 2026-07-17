import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { FileSpreadsheet } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { formatMoney } from "@/lib/invoiceTypes";
import { ESTIMATE_STATUS_META, EstimateStatus } from "@/lib/estimateTypes";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";

interface PublicLine {
  description: string;
  quantity: number;
  unitAmount: number;
  amount: number;
}

interface PublicEstimate {
  number: string | null;
  status: EstimateStatus;
  currency: string;
  from: string | null;
  billTo: string | null;
  notes: string | null;
  lineItems: PublicLine[];
  subtotal: number;
  taxAmount: number;
  total: number;
  decidedAt: string | null;
  logoUrl: string | null;
  terms: string | null;
}

/**
 * Public, unauthenticated estimate view (#652) a client reaches from the
 * shared link — review the quote, then Accept or Decline right here.
 */
const PublicEstimatePage = () => {
  const router = useRouter();
  const { token } = router.query;
  const shareToken = typeof token === "string" ? token : null;

  const [estimate, setEstimate] = useState<PublicEstimate | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deciding, setDeciding] = useState(false);

  useEffect(() => {
    if (!shareToken) return;
    let cancelled = false;
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/public/estimates/${encodeURIComponent(shareToken)}`,
          { headers: { Accept: "application/json" } },
        );
        if (cancelled) return;
        if (res.status === 404) {
          setNotFound(true);
          return;
        }
        if (!res.ok) throw new Error("Failed to load the estimate.");
        setEstimate((await res.json()) as PublicEstimate);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Failed to load the estimate.");
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [shareToken]);

  const decide = async (action: "accept" | "decline") => {
    if (!shareToken) return;
    setDeciding(true);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/public/estimates/${encodeURIComponent(shareToken)}/${action}`,
        { method: "POST", headers: { Accept: "application/json" } },
      );
      const data = (await res.json().catch(() => ({}))) as {
        status?: EstimateStatus;
        error?: string;
      };
      if (res.ok && data.status) {
        setEstimate((prev) => (prev ? { ...prev, status: data.status as EstimateStatus } : prev));
        return;
      }
      setError(data.error ?? "Could not record your decision.");
    } catch {
      setError("Could not record your decision.");
    } finally {
      setDeciding(false);
    }
  };

  let body;
  if (notFound) {
    body = (
      <div className="space-y-2 text-center">
        <FileSpreadsheet className="mx-auto h-10 w-10 text-muted-foreground" aria-hidden />
        <h1 className="text-xl font-semibold">Estimate not found</h1>
        <p className="text-sm text-muted-foreground">
          This link isn&apos;t valid — it may have been replaced by a newer one.
        </p>
      </div>
    );
  } else if (error && !estimate) {
    body = (
      <div className="space-y-2 text-center">
        <h1 className="text-xl font-semibold">Something went wrong</h1>
        <p className="text-sm text-muted-foreground">{error}</p>
      </div>
    );
  } else if (!estimate) {
    body = <p className="text-center text-sm text-muted-foreground">Loading estimate…</p>;
  } else {
    const meta = ESTIMATE_STATUS_META[estimate.status];
    body = (
      <div className="space-y-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            {estimate.logoUrl && (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={estimate.logoUrl}
                alt={estimate.from ?? "Logo"}
                className="mb-3 h-12 w-auto max-w-48 object-contain"
              />
            )}
            <h1 className="text-2xl font-semibold">Estimate {estimate.number ?? ""}</h1>
            {estimate.from && (
              <p className="text-sm text-muted-foreground">From {estimate.from}</p>
            )}
          </div>
          <span className={cn("rounded px-2 py-1 text-xs font-medium", meta.badgeClass)}>
            {meta.label}
          </span>
        </div>

        {estimate.billTo && (
          <div className="text-sm">
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Prepared for</p>
            <p>{estimate.billTo}</p>
          </div>
        )}

        <table className="w-full text-sm">
          <thead>
            <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
              <th className="py-2">Description</th>
              <th className="py-2 text-right">Qty</th>
              <th className="py-2 text-right">Unit</th>
              <th className="py-2 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            {estimate.lineItems.map((line, i) => (
              <tr key={i} className="border-b">
                <td className="py-2">{line.description}</td>
                <td className="py-2 text-right tabular-nums">{line.quantity}</td>
                <td className="py-2 text-right tabular-nums">
                  {formatMoney(line.unitAmount, estimate.currency)}
                </td>
                <td className="py-2 text-right tabular-nums">
                  {formatMoney(line.amount, estimate.currency)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="ml-auto w-full max-w-xs space-y-1 text-sm">
          <div className="flex justify-between">
            <span className="text-muted-foreground">Subtotal</span>
            <span className="tabular-nums">
              {formatMoney(estimate.subtotal, estimate.currency)}
            </span>
          </div>
          {estimate.taxAmount > 0 && (
            <div className="flex justify-between">
              <span className="text-muted-foreground">Tax</span>
              <span className="tabular-nums">
                {formatMoney(estimate.taxAmount, estimate.currency)}
              </span>
            </div>
          )}
          <div className="flex justify-between border-t pt-1 text-base font-semibold">
            <span>Total</span>
            <span className="tabular-nums">{formatMoney(estimate.total, estimate.currency)}</span>
          </div>
        </div>

        {estimate.notes && (
          <p className="whitespace-pre-wrap text-sm text-muted-foreground">{estimate.notes}</p>
        )}

        {error && <p className="text-center text-sm text-destructive">{error}</p>}

        <div className="flex flex-wrap items-center justify-end gap-3">
          {estimate.status === "sent" ? (
            <>
              <Button
                variant="outline"
                onClick={() => void decide("decline")}
                disabled={deciding}
              >
                Decline
              </Button>
              <Button onClick={() => void decide("accept")} disabled={deciding}>
                {deciding ? "Saving…" : "Accept estimate"}
              </Button>
            </>
          ) : estimate.status === "accepted" ? (
            <span className="text-sm font-medium text-emerald-600 dark:text-emerald-400">
              Accepted — thank you
            </span>
          ) : estimate.status === "declined" ? (
            <span className="text-sm font-medium text-muted-foreground">Declined</span>
          ) : null}
        </div>

        {estimate.terms && (
          <p className="whitespace-pre-wrap border-t pt-4 text-xs text-muted-foreground">
            {estimate.terms}
          </p>
        )}
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Estimate — Madori</title>
      </Head>
      <div className="container mx-auto max-w-2xl px-4 py-16">
        <Card>
          <CardContent className="py-8">{body}</CardContent>
        </Card>
      </div>
    </>
  );
};

export default PublicEstimatePage;
