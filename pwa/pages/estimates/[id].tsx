import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, Plus, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { apiGet, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { formatMoney } from "@/lib/invoiceTypes";
import { Estimate, ESTIMATE_STATUS_META, EstimateStatus, estimateClientName } from "@/lib/estimateTypes";
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

/** Estimate detail (#652) — the quote editor, mirroring the invoice detail. */
const EstimateDetailPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const { id } = router.query;
  const estimateId = typeof id === "string" ? id : null;

  const [lines, setLines] = useState<EditableLine[]>([]);
  const [notes, setNotes] = useState("");
  const [taxPercent, setTaxPercent] = useState("0");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const estimateQuery = useQuery({
    queryKey: ["estimate", estimateId],
    enabled: isAuthenticated && estimateId !== null,
    queryFn: () =>
      apiGet<Estimate>(`/estimates/${estimateId}`, {
        errorMessage: "Failed to load the estimate.",
      }),
  });
  const estimate = estimateQuery.data ?? null;
  const currency = estimate?.currency ?? "USD";
  const editable = estimate?.status === "draft" || estimate?.status === "sent";

  useEffect(() => {
    if (!estimate) return;
    setLines(
      estimate.lineItems.map((l, i) => ({
        key: `${i}`,
        description: l.description,
        quantity: String(l.quantity),
        unit: (l.unitAmount / 100).toFixed(2),
      })),
    );
    setNotes(estimate.notes ?? "");
    setTaxPercent(String(estimate.taxRate / 100));
  }, [estimate]);

  const preview = useMemo(() => {
    const subtotal = lines.reduce((sum, l) => {
      const qty = parseFloat(l.quantity) || 0;
      const unitMinor = Math.round((parseFloat(l.unit) || 0) * 100);
      return sum + Math.round(qty * unitMinor);
    }, 0);
    const tax = Math.round((subtotal * (parseFloat(taxPercent) || 0) * 100) / 10000);
    return { subtotal, tax, total: subtotal + tax };
  }, [lines, taxPercent]);

  const addLine = () =>
    setLines((prev) => [
      ...prev,
      { key: `new-${prev.length}-${Date.now()}`, description: "", quantity: "1", unit: "0.00" },
    ]);
  const removeLine = (key: string) => setLines((prev) => prev.filter((l) => l.key !== key));
  const updateLine = (key: string, patch: Partial<EditableLine>) =>
    setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)));

  const save = async () => {
    if (!estimate) return;
    setSaving(true);
    setError(null);
    try {
      await apiSend<Estimate>("PATCH", estimate["@id"], {
        contentType: MERGE_PATCH,
        errorMessage: "Failed to save.",
        body: {
          notes: notes.trim() || null,
          taxRate: Math.round((parseFloat(taxPercent) || 0) * 100),
          lineItems: lines.map((l, i) => ({
            description: l.description.trim() || "—",
            quantity: parseFloat(l.quantity) || 0,
            unitAmount: Math.round((parseFloat(l.unit) || 0) * 100),
            position: i,
          })),
        },
      });
      setNotice("Saved.");
      void estimateQuery.refetch();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to save.");
    } finally {
      setSaving(false);
    }
  };

  const runAction = async (action: "send" | "convert") => {
    if (!estimate) return;
    setError(null);
    try {
      const res = await apiSend<{ token?: string; invoice?: { id: string } }>(
        "POST",
        `${estimate["@id"]}/${action}`,
        {
          contentType: "application/json",
          errorMessage: `Failed to ${action}.`,
          body: {},
        },
      );
      if (action === "send" && res?.token) {
        setNotice(`Sent. Link: ${window.location.origin}/e/${res.token}`);
      }
      if (action === "convert" && res?.invoice?.id) {
        void router.push(`/invoices/${res.invoice.id}`);
        return;
      }
      void estimateQuery.refetch();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Action failed.");
    }
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  const status: EstimateStatus | null = estimate?.status ?? null;

  return (
    <>
      <Head>
        <title>Estimate — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-3xl">
          <Link
            href="/estimates"
            className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="size-4" /> Estimates
          </Link>

          {estimateQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : !estimate ? (
            <Alert variant="destructive">
              <AlertDescription>Estimate not found.</AlertDescription>
            </Alert>
          ) : (
            <>
              <div className="mb-6 flex items-center justify-between gap-4">
                <div>
                  <h1 className="flex items-center gap-2 text-2xl font-semibold">
                    {estimate.number ?? "Draft estimate"}
                    {status && (
                      <span
                        className={cn(
                          "rounded px-2 py-0.5 text-xs font-medium",
                          ESTIMATE_STATUS_META[status].badgeClass,
                        )}
                      >
                        {ESTIMATE_STATUS_META[status].label}
                      </span>
                    )}
                  </h1>
                  <p className="text-sm text-muted-foreground">
                    {estimateClientName(estimate.client)}
                    {estimate.decidedAt &&
                      ` · decided ${new Date(estimate.decidedAt).toLocaleDateString(undefined, { dateStyle: "medium" })}`}
                  </p>
                </div>
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
                          (parseFloat(line.quantity) || 0) *
                            Math.round((parseFloat(line.unit) || 0) * 100),
                        );
                        return (
                          <tr key={line.key} className="border-b">
                            <td className="py-1.5 pr-2">
                              {editable ? (
                                <Input
                                  value={line.description}
                                  onChange={(e) =>
                                    updateLine(line.key, { description: e.target.value })
                                  }
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
                                  onChange={(e) =>
                                    updateLine(line.key, { quantity: e.target.value })
                                  }
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
                                formatMoney(
                                  Math.round((parseFloat(line.unit) || 0) * 100),
                                  currency,
                                )
                              )}
                            </td>
                            <td className="py-1.5 text-right tabular-nums">
                              {formatMoney(amount, currency)}
                            </td>
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
                  </div>
                </CardContent>
              </Card>

              {editable && (
                <Card className="mb-6">
                  <CardContent className="space-y-4 py-6">
                    <div className="space-y-1.5">
                      <Label htmlFor="est-tax">Tax %</Label>
                      <Input
                        id="est-tax"
                        type="number"
                        step="0.01"
                        min="0"
                        value={taxPercent}
                        onChange={(e) => setTaxPercent(e.target.value)}
                        className="w-40"
                      />
                    </div>
                    <div className="space-y-1.5">
                      <Label htmlFor="est-notes">Notes</Label>
                      <Textarea
                        id="est-notes"
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        rows={2}
                      />
                    </div>
                    <Button onClick={() => void save()} disabled={saving}>
                      {saving ? "Saving…" : "Save"}
                    </Button>
                  </CardContent>
                </Card>
              )}

              <div className="flex flex-wrap gap-2">
                {status !== "declined" && (
                  <Button variant="outline" onClick={() => void runAction("send")}>
                    {status === "draft" ? "Send" : "Re-send"}
                  </Button>
                )}
                {status !== "declined" && !estimate.convertedInvoice && (
                  <Button variant="outline" onClick={() => void runAction("convert")}>
                    Convert to invoice
                  </Button>
                )}
                {estimate.convertedInvoice && (
                  <p className="self-center text-sm text-muted-foreground">
                    Converted to an invoice.
                  </p>
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </>
  );
};

export default EstimateDetailPage;
