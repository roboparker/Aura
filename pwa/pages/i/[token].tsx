import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { Download, Receipt } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { formatMoney, InvoiceStatus, STATUS_META } from "@/lib/invoiceTypes";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";

interface PublicLine {
  description: string;
  quantity: number;
  unitAmount: number;
  amount: number;
}

interface PublicInvoice {
  number: string | null;
  status: InvoiceStatus;
  currency: string;
  issueDate: string | null;
  dueDate: string | null;
  from: string | null;
  billTo: string | null;
  lineItems: PublicLine[];
  subtotal: number;
  discountAmount: number;
  taxAmount: number;
  total: number;
  amountPaid: number;
  balanceDue: number;
  payments: { amount: number; paidOn: string; method: string | null }[];
}

const fmtDate = (iso: string | null): string =>
  iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" }) : "—";

/**
 * Public, unauthenticated invoice view a client reaches from the shared link
 * (#445). Views the invoice, downloads the PDF, and pays online when a gateway
 * is configured.
 */
const PublicInvoicePage = () => {
  const router = useRouter();
  const { token } = router.query;
  const inviteToken = typeof token === "string" ? token : null;

  const [invoice, setInvoice] = useState<PublicInvoice | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [paying, setPaying] = useState(false);

  useEffect(() => {
    if (!inviteToken) return;
    let cancelled = false;
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/public/invoices/${encodeURIComponent(inviteToken)}`,
          { headers: { Accept: "application/json" } },
        );
        if (cancelled) return;
        if (res.status === 404) {
          setNotFound(true);
          return;
        }
        if (!res.ok) throw new Error("Failed to load the invoice.");
        setInvoice((await res.json()) as PublicInvoice);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Failed to load the invoice.");
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [inviteToken]);

  const downloadPdf = async () => {
    if (!inviteToken) return;
    try {
      const res = await fetch(
        `${ENTRYPOINT}/public/invoices/${encodeURIComponent(inviteToken)}/pdf`,
        { headers: { Accept: "application/pdf" } },
      );
      if (!res.ok) {
        setError("Could not open the PDF.");
        return;
      }
      window.open(URL.createObjectURL(await res.blob()), "_blank");
    } catch {
      setError("Could not open the PDF.");
    }
  };

  const payOnline = async () => {
    if (!inviteToken) return;
    setPaying(true);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/public/invoices/${encodeURIComponent(inviteToken)}/pay`,
        { method: "POST", headers: { Accept: "application/json" } },
      );
      const data = (await res.json().catch(() => ({}))) as { url?: string; error?: string };
      if (res.ok && data.url) {
        window.location.href = data.url;
        return;
      }
      setError(data.error ?? "Online payment is not available.");
    } catch {
      setError("Could not start payment.");
    } finally {
      setPaying(false);
    }
  };

  let body;
  if (notFound) {
    body = (
      <div className="space-y-2 text-center">
        <Receipt className="mx-auto h-10 w-10 text-muted-foreground" aria-hidden />
        <h1 className="text-xl font-semibold">Invoice not found</h1>
        <p className="text-sm text-muted-foreground">
          This link isn&apos;t valid — it may have been revoked or the invoice voided.
        </p>
      </div>
    );
  } else if (error && !invoice) {
    body = (
      <div className="space-y-2 text-center">
        <h1 className="text-xl font-semibold">Something went wrong</h1>
        <p className="text-sm text-muted-foreground">{error}</p>
      </div>
    );
  } else if (!invoice) {
    body = <p className="text-center text-sm text-muted-foreground">Loading invoice…</p>;
  } else {
    const meta = STATUS_META[invoice.status];
    body = (
      <div className="space-y-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold">Invoice {invoice.number ?? ""}</h1>
            {invoice.from && <p className="text-sm text-muted-foreground">From {invoice.from}</p>}
          </div>
          <span className={cn("rounded px-2 py-1 text-xs font-medium", meta.badgeClass)}>
            {meta.label}
          </span>
        </div>

        <div className="grid gap-4 text-sm sm:grid-cols-3">
          <div>
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Bill to</p>
            <p>{invoice.billTo ?? "—"}</p>
          </div>
          <div>
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Issued</p>
            <p>{fmtDate(invoice.issueDate)}</p>
          </div>
          <div>
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Due</p>
            <p>{fmtDate(invoice.dueDate)}</p>
          </div>
        </div>

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
            {invoice.lineItems.map((line, i) => (
              <tr key={i} className="border-b">
                <td className="py-2">{line.description}</td>
                <td className="py-2 text-right tabular-nums">{line.quantity}</td>
                <td className="py-2 text-right tabular-nums">
                  {formatMoney(line.unitAmount, invoice.currency)}
                </td>
                <td className="py-2 text-right tabular-nums">
                  {formatMoney(line.amount, invoice.currency)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="ml-auto w-full max-w-xs space-y-1 text-sm">
          <div className="flex justify-between">
            <span className="text-muted-foreground">Subtotal</span>
            <span className="tabular-nums">{formatMoney(invoice.subtotal, invoice.currency)}</span>
          </div>
          {invoice.discountAmount > 0 && (
            <div className="flex justify-between">
              <span className="text-muted-foreground">Discount</span>
              <span className="tabular-nums">
                −{formatMoney(invoice.discountAmount, invoice.currency)}
              </span>
            </div>
          )}
          {invoice.taxAmount > 0 && (
            <div className="flex justify-between">
              <span className="text-muted-foreground">Tax</span>
              <span className="tabular-nums">{formatMoney(invoice.taxAmount, invoice.currency)}</span>
            </div>
          )}
          <div className="flex justify-between border-t pt-1 text-base font-semibold">
            <span>Total</span>
            <span className="tabular-nums">{formatMoney(invoice.total, invoice.currency)}</span>
          </div>
          {invoice.amountPaid > 0 && (
            <>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Amount paid</span>
                <span className="tabular-nums">
                  −{formatMoney(invoice.amountPaid, invoice.currency)}
                </span>
              </div>
              <div className="flex justify-between border-t pt-1 text-base font-semibold">
                <span>Balance due</span>
                <span className="tabular-nums">
                  {formatMoney(invoice.balanceDue, invoice.currency)}
                </span>
              </div>
            </>
          )}
        </div>

        {invoice.payments.length > 0 && (
          <div className="text-sm">
            <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Payments</p>
            {invoice.payments.map((p, i) => (
              <div key={i} className="flex justify-between border-b py-1 last:border-0">
                <span className="text-muted-foreground">
                  {fmtDate(p.paidOn)}
                  {p.method ? ` · ${p.method}` : ""}
                </span>
                <span className="tabular-nums">{formatMoney(p.amount, invoice.currency)}</span>
              </div>
            ))}
          </div>
        )}

        {error && <p className="text-center text-sm text-destructive">{error}</p>}

        <div className="flex flex-wrap items-center justify-end gap-3">
          <Button variant="outline" onClick={() => void downloadPdf()} className="gap-1.5">
            <Download className="h-4 w-4" aria-hidden /> PDF
          </Button>
          {invoice.status !== "paid" && invoice.status !== "void" && invoice.balanceDue > 0 && (
            <Button onClick={() => void payOnline()} disabled={paying}>
              {paying
                ? "Starting…"
                : invoice.amountPaid > 0
                  ? `Pay ${formatMoney(invoice.balanceDue, invoice.currency)}`
                  : "Pay online"}
            </Button>
          )}
          {invoice.status === "paid" && (
            <span className="text-sm font-medium text-emerald-600 dark:text-emerald-400">
              Paid — thank you
            </span>
          )}
        </div>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Invoice — Madori</title>
      </Head>
      <div className="container mx-auto max-w-2xl px-4 py-16">
        <Card>
          <CardContent className="py-8">{body}</CardContent>
        </Card>
      </div>
    </>
  );
};

export default PublicInvoicePage;
