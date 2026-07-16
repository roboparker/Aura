import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { Download, Receipt } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { InvoiceStatus, STATUS_META, formatMoney } from "@/lib/invoiceTypes";
import { ESTIMATE_STATUS_META, EstimateStatus } from "@/lib/estimateTypes";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";

interface PortalInvoice {
  id: string;
  number: string | null;
  status: InvoiceStatus;
  issueDate: string | null;
  dueDate: string | null;
  currency: string;
  total: number;
  amountPaid: number;
  balanceDue: number;
}

interface PortalEstimate {
  id: string;
  number: string | null;
  status: EstimateStatus;
  currency: string;
  total: number;
  decidedAt: string | null;
}

interface PortalPayload {
  clientName: string;
  from: string | null;
  logoUrl: string | null;
  invoices: PortalInvoice[];
  estimates: PortalEstimate[];
}

const fmtDate = (iso: string | null): string =>
  iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" }) : "—";

/**
 * Client billing portal (#674): the one link a client keeps — every invoice
 * and estimate they've received, with PDF downloads, online payment, and
 * estimate accept/decline, all against portal-scoped endpoints.
 */
const ClientPortalPage = () => {
  const router = useRouter();
  const { token } = router.query;
  const portalToken = typeof token === "string" ? token : null;

  const [portal, setPortal] = useState<PortalPayload | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!portalToken) return;
    try {
      const res = await fetch(`${ENTRYPOINT}/portal/${encodeURIComponent(portalToken)}`, {
        headers: { Accept: "application/json" },
      });
      if (res.status === 404) {
        setNotFound(true);
        return;
      }
      if (!res.ok) throw new Error();
      setPortal((await res.json()) as PortalPayload);
    } catch {
      setError("Could not load the portal.");
    }
  }, [portalToken]);

  useEffect(() => {
    void load();
  }, [load]);

  const downloadPdf = async (invoice: PortalInvoice) => {
    if (!portalToken) return;
    try {
      const res = await fetch(
        `${ENTRYPOINT}/portal/${encodeURIComponent(portalToken)}/invoices/${invoice.id}/pdf`,
      );
      if (!res.ok) {
        setError("Could not download the PDF.");
        return;
      }
      const blob = await res.blob();
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = `${invoice.number ?? "invoice"}.pdf`;
      document.body.appendChild(a);
      a.click();
      a.remove();
    } catch {
      setError("Could not download the PDF.");
    }
  };

  const payInvoice = async (invoice: PortalInvoice) => {
    if (!portalToken) return;
    setBusy(invoice.id);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/portal/${encodeURIComponent(portalToken)}/invoices/${invoice.id}/pay`,
        { method: "POST", headers: { "Content-Type": "application/json" }, body: "{}" },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        setError(data.error || "Could not start the payment.");
        return;
      }
      if (typeof data.url === "string") window.location.href = data.url;
    } catch {
      setError("Could not start the payment.");
    } finally {
      setBusy(null);
    }
  };

  const decideEstimate = async (estimate: PortalEstimate, action: "accept" | "decline") => {
    if (!portalToken) return;
    setBusy(estimate.id);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/portal/${encodeURIComponent(portalToken)}/estimates/${estimate.id}/${action}`,
        { method: "POST", headers: { "Content-Type": "application/json" }, body: "{}" },
      );
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        setError(data.error || `Could not ${action} the estimate.`);
        return;
      }
      await load();
    } catch {
      setError(`Could not ${action} the estimate.`);
    } finally {
      setBusy(null);
    }
  };

  let body;
  if (notFound) {
    body = (
      <div className="text-center">
        <h1 className="text-xl font-semibold">Portal not found</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          This link is invalid or has been replaced by a newer one.
        </p>
      </div>
    );
  } else if (!portal) {
    body = <p className="text-center text-sm text-muted-foreground">Loading…</p>;
  } else {
    body = (
      <div className="space-y-8">
        <div>
          {portal.logoUrl && (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={portal.logoUrl}
              alt={portal.from ?? "Logo"}
              className="mb-3 h-12 w-auto max-w-48 object-contain"
            />
          )}
          <h1 className="text-2xl font-semibold">Billing portal</h1>
          <p className="text-sm text-muted-foreground">
            {portal.clientName}
            {portal.from ? ` · from ${portal.from}` : ""}
          </p>
        </div>

        {error && <p className="text-sm text-destructive">{error}</p>}

        <section className="space-y-2">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
            Invoices
          </h2>
          {portal.invoices.length === 0 ? (
            <p className="text-sm text-muted-foreground">No invoices yet.</p>
          ) : (
            <div className="overflow-x-auto rounded-md border">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="px-3 py-2">Invoice</th>
                    <th className="px-3 py-2">Issued</th>
                    <th className="px-3 py-2">Due</th>
                    <th className="px-3 py-2 text-right">Total</th>
                    <th className="px-3 py-2 text-right">Balance</th>
                    <th className="px-3 py-2" />
                  </tr>
                </thead>
                <tbody>
                  {portal.invoices.map((invoice) => {
                    const meta = STATUS_META[invoice.status];
                    return (
                      <tr key={invoice.id} className="border-b align-middle last:border-0">
                        <td className="px-3 py-2.5">
                          <span className="font-medium">{invoice.number ?? "—"}</span>
                          <span
                            className={cn(
                              "ml-2 rounded px-1.5 py-0.5 text-xs font-medium",
                              meta.badgeClass,
                            )}
                          >
                            {meta.label}
                          </span>
                        </td>
                        <td className="px-3 py-2.5 text-muted-foreground">
                          {fmtDate(invoice.issueDate)}
                        </td>
                        <td className="px-3 py-2.5 text-muted-foreground">
                          {fmtDate(invoice.dueDate)}
                        </td>
                        <td className="px-3 py-2.5 text-right tabular-nums">
                          {formatMoney(invoice.total, invoice.currency)}
                        </td>
                        <td className="px-3 py-2.5 text-right tabular-nums">
                          {formatMoney(invoice.balanceDue, invoice.currency)}
                        </td>
                        <td className="px-3 py-2.5 text-right">
                          <span className="inline-flex items-center gap-2">
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => void downloadPdf(invoice)}
                            >
                              <Download className="h-3.5 w-3.5" /> PDF
                            </Button>
                            {invoice.status !== "paid" && invoice.balanceDue > 0 && (
                              <Button
                                size="sm"
                                onClick={() => void payInvoice(invoice)}
                                disabled={busy === invoice.id}
                              >
                                {busy === invoice.id
                                  ? "Starting…"
                                  : `Pay ${formatMoney(invoice.balanceDue, invoice.currency)}`}
                              </Button>
                            )}
                          </span>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section className="space-y-2">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
            Estimates
          </h2>
          {portal.estimates.length === 0 ? (
            <p className="text-sm text-muted-foreground">No estimates yet.</p>
          ) : (
            <div className="overflow-x-auto rounded-md border">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="px-3 py-2">Estimate</th>
                    <th className="px-3 py-2 text-right">Total</th>
                    <th className="px-3 py-2" />
                  </tr>
                </thead>
                <tbody>
                  {portal.estimates.map((estimate) => {
                    const meta = ESTIMATE_STATUS_META[estimate.status];
                    return (
                      <tr key={estimate.id} className="border-b align-middle last:border-0">
                        <td className="px-3 py-2.5">
                          <span className="font-medium">{estimate.number ?? "—"}</span>
                          <span
                            className={cn(
                              "ml-2 rounded px-1.5 py-0.5 text-xs font-medium",
                              meta.badgeClass,
                            )}
                          >
                            {meta.label}
                          </span>
                        </td>
                        <td className="px-3 py-2.5 text-right tabular-nums">
                          {formatMoney(estimate.total, estimate.currency)}
                        </td>
                        <td className="px-3 py-2.5 text-right">
                          {estimate.status === "sent" && (
                            <span className="inline-flex items-center gap-2">
                              <Button
                                variant="outline"
                                size="sm"
                                onClick={() => void decideEstimate(estimate, "decline")}
                                disabled={busy === estimate.id}
                              >
                                Decline
                              </Button>
                              <Button
                                size="sm"
                                onClick={() => void decideEstimate(estimate, "accept")}
                                disabled={busy === estimate.id}
                              >
                                Accept
                              </Button>
                            </span>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Billing portal — Madori</title>
      </Head>
      <div className="flex min-h-screen items-start justify-center bg-muted px-4 py-12">
        <Card className="w-full max-w-3xl">
          <CardContent className="pt-6">
            <div className="mb-4 flex items-center gap-2 text-muted-foreground">
              <Receipt className="h-4 w-4" aria-hidden />
              <span className="text-xs uppercase tracking-wide">Madori billing</span>
            </div>
            {body}
          </CardContent>
        </Card>
      </div>
    </>
  );
};

export default ClientPortalPage;
