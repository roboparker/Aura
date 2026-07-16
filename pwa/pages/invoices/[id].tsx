import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, Download, Plus, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { apiGet, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  Invoice,
  InvoiceStatus,
  STATUS_META,
  clientName,
  formatMoney,
} from "@/lib/invoiceTypes";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { cn } from "@/lib/utils";

const MERGE_PATCH = "application/merge-patch+json";

interface EditableLine {
  key: string;
  description: string;
  quantity: string;
  unit: string; // major units
}

const InvoiceDetailPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const { id } = router.query;
  const invoiceId = typeof id === "string" ? id : null;

  const [lines, setLines] = useState<EditableLine[]>([]);
  const [dueDate, setDueDate] = useState("");
  const [notes, setNotes] = useState("");
  const [taxPercent, setTaxPercent] = useState("0");
  const [discType, setDiscType] = useState("");
  const [discValue, setDiscValue] = useState("");
  const [payAmount, setPayAmount] = useState("");
  const [payDate, setPayDate] = useState("");
  const [payMethod, setPayMethod] = useState("");
  const [payNote, setPayNote] = useState("");
  const [payBusy, setPayBusy] = useState(false);
  const [freq, setFreq] = useState("");
  const [interval, setInterval] = useState("1");
  const [nextIssue, setNextIssue] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const invoiceQuery = useQuery({
    queryKey: ["invoice", invoiceId],
    enabled: isAuthenticated && invoiceId !== null,
    queryFn: () =>
      apiGet<Invoice>(`/invoices/${invoiceId}`, { errorMessage: "Failed to load the invoice." }),
  });
  const invoice = invoiceQuery.data ?? null;
  const currency = invoice?.currency ?? "USD";
  const editable = invoice?.status === "draft";

  // Seed the editable state once the invoice loads.
  useEffect(() => {
    if (!invoice) return;
    setLines(
      invoice.lineItems.map((l, i) => ({
        key: `${i}`,
        description: l.description,
        quantity: String(l.quantity),
        unit: (l.unitAmount / 100).toFixed(2),
      })),
    );
    setDueDate(invoice.dueDate ?? "");
    setNotes(invoice.notes ?? "");
    setTaxPercent(String(invoice.taxRate / 100));
    setDiscType(invoice.discountType ?? "");
    setDiscValue(
      invoice.discountValue === null
        ? ""
        : invoice.discountType === "percent"
          ? String(invoice.discountValue / 100)
          : (invoice.discountValue / 100).toFixed(2),
    );
    setFreq(invoice.recurrenceFrequency ?? "");
    setInterval(String(invoice.recurrenceInterval ?? 1));
    setNextIssue(invoice.nextIssueDate ?? "");
  }, [invoice]);

  const preview = useMemo(() => {
    const subtotal = lines.reduce((sum, l) => {
      const qty = parseFloat(l.quantity) || 0;
      const unitMinor = Math.round((parseFloat(l.unit) || 0) * 100);
      return sum + Math.round(qty * unitMinor);
    }, 0);
    // Discount between subtotal and tax, mirroring InvoiceProcessor.
    let discount = 0;
    if (discType === "percent") {
      discount = Math.round((subtotal * (parseFloat(discValue) || 0) * 100) / 10000);
    } else if (discType === "fixed") {
      discount = Math.round((parseFloat(discValue) || 0) * 100);
    }
    discount = Math.max(0, Math.min(discount, subtotal));
    const taxable = subtotal - discount;
    const tax = Math.round((taxable * (parseFloat(taxPercent) || 0) * 100) / 10000);
    return { subtotal, discount, tax, total: taxable + tax };
  }, [lines, taxPercent, discType, discValue]);

  const addLine = () =>
    setLines((prev) => [
      ...prev,
      { key: `new-${prev.length}-${Date.now()}`, description: "", quantity: "1", unit: "0.00" },
    ]);
  const removeLine = (key: string) => setLines((prev) => prev.filter((l) => l.key !== key));
  const updateLine = (key: string, patch: Partial<EditableLine>) =>
    setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)));

  const save = async () => {
    if (!invoice) return;
    setSaving(true);
    setError(null);
    try {
      const recurring = freq !== "";
      await apiSend<Invoice>("PATCH", invoice["@id"], {
        contentType: MERGE_PATCH,
        errorMessage: "Failed to save.",
        body: {
          dueDate: dueDate || null,
          notes: notes.trim() || null,
          taxRate: Math.round((parseFloat(taxPercent) || 0) * 100),
          discountType: discType || null,
          discountValue:
            discType === ""
              ? null
              : Math.round((parseFloat(discValue) || 0) * 100),
          lineItems: lines.map((l, i) => ({
            description: l.description.trim() || "—",
            quantity: parseFloat(l.quantity) || 0,
            unitAmount: Math.round((parseFloat(l.unit) || 0) * 100),
            position: i,
          })),
          recurrenceFrequency: recurring ? freq : null,
          recurrenceInterval: recurring ? parseInt(interval, 10) || 1 : null,
          nextIssueDate: recurring ? nextIssue || null : null,
        },
      });
      setNotice("Saved.");
      void invoiceQuery.refetch();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to save.");
    } finally {
      setSaving(false);
    }
  };

  const runAction = async (action: string) => {
    if (!invoice) return;
    setError(null);
    try {
      const res = await apiSend<{ token?: string }>("POST", `${invoice["@id"]}/${action}`, {
        contentType: "application/json",
        errorMessage: `Failed to ${action.replace("-", " ")}.`,
        body: {},
      });
      if (action === "send" && res?.token) {
        setNotice(`Sent. Link: ${window.location.origin}/i/${res.token}`);
      }
      void invoiceQuery.refetch();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Action failed.");
    }
  };

  // Partial payments (#648): record against / remove from the ledger.
  const recordPayment = async () => {
    if (!invoice) return;
    const amountMinor = Math.round((parseFloat(payAmount) || 0) * 100);
    if (amountMinor <= 0) {
      setError("Enter a payment amount.");
      return;
    }
    setPayBusy(true);
    setError(null);
    try {
      await apiSend("POST", `${invoice["@id"]}/payments`, {
        contentType: "application/json",
        errorMessage: "Failed to record the payment.",
        body: {
          amount: amountMinor,
          ...(payDate ? { paidOn: payDate } : {}),
          ...(payMethod.trim() ? { method: payMethod.trim() } : {}),
          ...(payNote.trim() ? { note: payNote.trim() } : {}),
        },
      });
      setPayAmount("");
      setPayDate("");
      setPayMethod("");
      setPayNote("");
      setNotice("Payment recorded.");
      void invoiceQuery.refetch();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to record the payment.");
    } finally {
      setPayBusy(false);
    }
  };

  const deletePayment = async (paymentId: string) => {
    if (!invoice) return;
    if (!window.confirm("Remove this payment?")) return;
    setError(null);
    try {
      await apiSend("DELETE", `${invoice["@id"]}/payments/${paymentId}`, {
        errorMessage: "Failed to remove the payment.",
      });
      void invoiceQuery.refetch();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to remove the payment.");
    }
  };

  const openPdf = async () => {
    if (!invoice) return;
    const res = await fetch(`${invoice["@id"]}/pdf`, {
      credentials: "include",
      headers: { Accept: "application/pdf" },
    });
    if (res.ok) window.open(URL.createObjectURL(await res.blob()), "_blank");
    else setError("Could not open the PDF.");
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  const status: InvoiceStatus | null = invoice?.status ?? null;

  return (
    <>
      <Head>
        <title>Invoice — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-3xl">
          <Link
            href="/invoices"
            className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="size-4" /> Invoices
          </Link>

          {invoiceQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : !invoice ? (
            <Alert variant="destructive">
              <AlertDescription>Invoice not found.</AlertDescription>
            </Alert>
          ) : (
            <>
              <div className="mb-6 flex items-center justify-between gap-4">
                <div>
                  <h1 className="flex items-center gap-2 text-2xl font-semibold">
                    {invoice.number ?? "Draft invoice"}
                    {status && (
                      <span
                        className={cn(
                          "rounded px-2 py-0.5 text-xs font-medium",
                          STATUS_META[status].badgeClass,
                        )}
                      >
                        {STATUS_META[status].label}
                      </span>
                    )}
                  </h1>
                  <p className="text-sm text-muted-foreground">{clientName(invoice.client)}</p>
                </div>
                <Button variant="outline" size="sm" onClick={() => void openPdf()} className="gap-1.5">
                  <Download className="size-4" /> PDF
                </Button>
              </div>

              {notice && (
                <Alert className="mb-4">
                  <AlertDescription>{notice}</AlertDescription>
                </Alert>
              )}
              {error && (
                <Alert variant="destructive" className="mb-4">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <Card className="mb-6">
                <CardContent className="space-y-4 py-6">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th className="py-2">Description</th>
                        <th className="w-20 py-2 text-right">Qty</th>
                        <th className="w-28 py-2 text-right">Unit</th>
                        <th className="w-28 py-2 text-right">Amount</th>
                        {editable && <th className="w-8" />}
                      </tr>
                    </thead>
                    <tbody>
                      {lines.map((line) => {
                        const amount = Math.round(
                          (parseFloat(line.quantity) || 0) * Math.round((parseFloat(line.unit) || 0) * 100),
                        );
                        return (
                          <tr key={line.key} className="border-b">
                            <td className="py-1.5 pr-2">
                              {editable ? (
                                <Input
                                  value={line.description}
                                  onChange={(e) => updateLine(line.key, { description: e.target.value })}
                                  className="h-8"
                                />
                              ) : (
                                line.description
                              )}
                            </td>
                            <td className="py-1.5 text-right">
                              {editable ? (
                                <Input
                                  type="number"
                                  step="0.25"
                                  value={line.quantity}
                                  onChange={(e) => updateLine(line.key, { quantity: e.target.value })}
                                  className="h-8 text-right"
                                />
                              ) : (
                                line.quantity
                              )}
                            </td>
                            <td className="py-1.5 text-right">
                              {editable ? (
                                <Input
                                  type="number"
                                  step="0.01"
                                  value={line.unit}
                                  onChange={(e) => updateLine(line.key, { unit: e.target.value })}
                                  className="h-8 text-right"
                                />
                              ) : (
                                formatMoney(Math.round((parseFloat(line.unit) || 0) * 100), currency)
                              )}
                            </td>
                            <td className="py-1.5 text-right tabular-nums">{formatMoney(amount, currency)}</td>
                            {editable && (
                              <td className="py-1.5 text-right">
                                <Button
                                  variant="ghost"
                                  size="icon"
                                  onClick={() => removeLine(line.key)}
                                  aria-label="Remove line"
                                >
                                  <Trash2 className="size-4 text-muted-foreground" />
                                </Button>
                              </td>
                            )}
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>

                  {editable && (
                    <Button variant="outline" size="sm" onClick={addLine} className="gap-1.5">
                      <Plus className="size-4" /> Add line
                    </Button>
                  )}

                  <div className="ml-auto w-full max-w-xs space-y-1 text-sm">
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Subtotal</span>
                      <span className="tabular-nums">{formatMoney(preview.subtotal, currency)}</span>
                    </div>
                    {preview.discount > 0 && (
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Discount</span>
                        <span className="tabular-nums">
                          −{formatMoney(preview.discount, currency)}
                        </span>
                      </div>
                    )}
                    {preview.tax > 0 && (
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Tax</span>
                        <span className="tabular-nums">{formatMoney(preview.tax, currency)}</span>
                      </div>
                    )}
                    <div className="flex justify-between border-t pt-1 text-base font-semibold">
                      <span>Total</span>
                      <span className="tabular-nums">{formatMoney(preview.total, currency)}</span>
                    </div>
                    {invoice.amountPaid > 0 && (
                      <>
                        <div className="flex justify-between">
                          <span className="text-muted-foreground">Paid</span>
                          <span className="tabular-nums">
                            −{formatMoney(invoice.amountPaid, currency)}
                          </span>
                        </div>
                        <div className="flex justify-between border-t pt-1 font-semibold">
                          <span>Balance due</span>
                          <span className="tabular-nums">
                            {formatMoney(invoice.balanceDue, currency)}
                          </span>
                        </div>
                      </>
                    )}
                  </div>
                </CardContent>
              </Card>

              {editable && (
                <Card className="mb-6">
                  <CardContent className="space-y-4 py-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div className="space-y-1.5">
                        <Label htmlFor="inv-due">Due date</Label>
                        <Input
                          id="inv-due"
                          type="date"
                          value={dueDate}
                          onChange={(e) => setDueDate(e.target.value)}
                        />
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor="inv-tax">Tax %</Label>
                        <Input
                          id="inv-tax"
                          type="number"
                          step="0.01"
                          min="0"
                          value={taxPercent}
                          onChange={(e) => setTaxPercent(e.target.value)}
                        />
                      </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div className="space-y-1.5">
                        <Label htmlFor="inv-disc-type">Discount</Label>
                        <select
                          id="inv-disc-type"
                          value={discType}
                          onChange={(e) => setDiscType(e.target.value)}
                          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                          <option value="">None</option>
                          <option value="percent">Percent</option>
                          <option value="fixed">Fixed amount</option>
                        </select>
                      </div>
                      {discType !== "" && (
                        <div className="space-y-1.5">
                          <Label htmlFor="inv-disc-value">
                            {discType === "percent" ? "Discount %" : "Discount amount"}
                          </Label>
                          <Input
                            id="inv-disc-value"
                            type="number"
                            step="0.01"
                            min="0"
                            value={discValue}
                            onChange={(e) => setDiscValue(e.target.value)}
                          />
                        </div>
                      )}
                    </div>
                    <div className="space-y-1.5">
                      <Label htmlFor="inv-notes">Notes</Label>
                      <Textarea
                        id="inv-notes"
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        rows={2}
                      />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                      <div className="space-y-1.5">
                        <Label htmlFor="inv-freq">Repeat</Label>
                        <select
                          id="inv-freq"
                          value={freq}
                          onChange={(e) => setFreq(e.target.value)}
                          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                          <option value="">Never</option>
                          <option value="weekly">Weekly</option>
                          <option value="monthly">Monthly</option>
                          <option value="yearly">Yearly</option>
                        </select>
                      </div>
                      {freq !== "" && (
                        <>
                          <div className="space-y-1.5">
                            <Label htmlFor="inv-interval">Every</Label>
                            <Input
                              id="inv-interval"
                              type="number"
                              min="1"
                              value={interval}
                              onChange={(e) => setInterval(e.target.value)}
                            />
                          </div>
                          <div className="space-y-1.5">
                            <Label htmlFor="inv-next">Next issue</Label>
                            <Input
                              id="inv-next"
                              type="date"
                              value={nextIssue}
                              onChange={(e) => setNextIssue(e.target.value)}
                            />
                          </div>
                        </>
                      )}
                    </div>
                    <Button onClick={() => void save()} disabled={saving}>
                      {saving ? "Saving…" : "Save"}
                    </Button>
                  </CardContent>
                </Card>
              )}

              {(status === "sent" || status === "overdue" || status === "paid") && (
                <Card className="mb-6">
                  <CardContent className="space-y-4 py-6">
                    <div className="flex items-center justify-between">
                      <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Payments
                      </h2>
                      <span className="text-sm text-muted-foreground">
                        Balance due:{" "}
                        <span className="font-semibold text-foreground tabular-nums">
                          {formatMoney(invoice.balanceDue, currency)}
                        </span>
                      </span>
                    </div>

                    {invoice.payments.length === 0 ? (
                      <p className="text-sm text-muted-foreground">No payments recorded yet.</p>
                    ) : (
                      <table className="w-full text-sm">
                        <tbody>
                          {invoice.payments.map((p) => (
                            <tr key={p.id} className="border-b last:border-0">
                              <td className="py-1.5 text-muted-foreground">
                                {new Date(p.paidOn).toLocaleDateString(undefined, {
                                  dateStyle: "medium",
                                })}
                              </td>
                              <td className="py-1.5">{p.method ?? "payment"}</td>
                              <td className="py-1.5 text-muted-foreground">{p.note}</td>
                              <td className="py-1.5 text-right tabular-nums">
                                {formatMoney(p.amount, currency)}
                              </td>
                              <td className="w-8 py-1.5 text-right">
                                <Button
                                  variant="ghost"
                                  size="icon"
                                  onClick={() => void deletePayment(p.id)}
                                  aria-label="Remove payment"
                                >
                                  <Trash2 className="size-4 text-muted-foreground" />
                                </Button>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}

                    {invoice.balanceDue > 0 && (
                      <div className="flex flex-wrap items-end gap-3">
                        <div className="space-y-1.5">
                          <Label htmlFor="pay-amount">Amount</Label>
                          <Input
                            id="pay-amount"
                            type="number"
                            step="0.01"
                            min="0"
                            value={payAmount}
                            onChange={(e) => setPayAmount(e.target.value)}
                            className="w-32"
                          />
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="pay-date">Date</Label>
                          <Input
                            id="pay-date"
                            type="date"
                            value={payDate}
                            onChange={(e) => setPayDate(e.target.value)}
                            className="w-40"
                          />
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="pay-method">Method</Label>
                          <Input
                            id="pay-method"
                            placeholder="bank transfer"
                            value={payMethod}
                            onChange={(e) => setPayMethod(e.target.value)}
                            className="w-40"
                          />
                        </div>
                        <div className="min-w-40 flex-1 space-y-1.5">
                          <Label htmlFor="pay-note">Note</Label>
                          <Input
                            id="pay-note"
                            value={payNote}
                            onChange={(e) => setPayNote(e.target.value)}
                          />
                        </div>
                        <Button
                          size="sm"
                          onClick={() => void recordPayment()}
                          disabled={payBusy}
                        >
                          {payBusy ? "Recording…" : "Record payment"}
                        </Button>
                      </div>
                    )}
                  </CardContent>
                </Card>
              )}

              {status !== "void" && (
                <div className="flex flex-wrap gap-2">
                  {status === "draft" && (
                    <Button variant="outline" onClick={() => void runAction("issue")}>
                      Issue
                    </Button>
                  )}
                  <Button variant="outline" onClick={() => void runAction("send")}>
                    Send
                  </Button>
                  {status !== "paid" && (
                    <Button variant="outline" onClick={() => void runAction("mark-paid")}>
                      Mark paid
                    </Button>
                  )}
                  <Button
                    variant="ghost"
                    className="text-destructive"
                    onClick={() => void runAction("void")}
                  >
                    Void
                  </Button>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </>
  );
};

export default InvoiceDetailPage;
