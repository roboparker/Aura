import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { MoreHorizontal, Plus, Receipt } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Invoice, STATUS_META, clientName, formatMoney } from "@/lib/invoiceTypes";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

type InvoiceAction = "issue" | "send" | "mark-paid" | "void";

/** Minimal engagement shape for the "generate from time" picker. */
interface EngagementLite {
  "@id": string;
  name: string;
}

const InvoicesPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [showComposer, setShowComposer] = useState(false);
  const [genEngagement, setGenEngagement] = useState("");
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceIri = activeSpace?.["@id"] ?? null;

  const invoicesQuery = useQuery({
    queryKey: ["invoices", spaceIri],
    enabled: isAuthenticated,
    queryFn: () =>
      apiGetCollection<Invoice>(
        spaceIri ? `/invoices?space=${encodeURIComponent(spaceIri)}` : "/invoices",
        { errorMessage: "Failed to load invoices." },
      ),
  });
  // Invoices generate from an *engagement* (Harvest model): the engagement
  // carries the client + currency and its unbilled time becomes line items.
  const engagementsQuery = useQuery({
    queryKey: ["engagements", spaceIri],
    enabled: isAuthenticated && can("invoices", "create"),
    queryFn: () =>
      apiGetCollection<EngagementLite>(
        spaceIri ? `/engagements?space=${encodeURIComponent(spaceIri)}` : "/engagements",
        { errorMessage: "Failed to load engagements." },
      ),
  });
  const invoices = invoicesQuery.data ?? [];
  const engagements = engagementsQuery.data ?? [];
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["invoices"] });

  const generate = useMutation({
    mutationFn: () =>
      apiSend<{ id: string; lineItemCount: number }>("POST", "/invoices/from-time-entries", {
        contentType: "application/json",
        errorMessage: "Failed to generate an invoice.",
        body: { engagement: genEngagement },
      }),
    onSuccess: (res) => {
      setError(null);
      const n = res?.lineItemCount ?? 0;
      setNotice(`Draft invoice created from ${n} time entr${n === 1 ? "y" : "ies"}.`);
      setGenEngagement("");
      setShowComposer(false);
      void refresh();
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Failed to generate an invoice."),
  });

  const act = useMutation({
    mutationFn: ({ invoice, action }: { invoice: Invoice; action: InvoiceAction }) =>
      apiSend<{ token?: string; publicUrl?: string }>("POST", `${invoice["@id"]}/${action}`, {
        contentType: "application/json",
        errorMessage: `Failed to ${action.replace("-", " ")} the invoice.`,
        body: {},
      }),
    onSuccess: (res, { action }) => {
      setError(null);
      if (action === "send" && res?.token) {
        setNotice(`Invoice sent. Shareable link: ${window.location.origin}/i/${res.token}`);
      } else {
        setNotice(null);
      }
      void refresh();
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Action failed."),
  });

  const openPdf = async (invoice: Invoice) => {
    try {
      const res = await fetch(`${invoice["@id"]}/pdf`, {
        credentials: "include",
        headers: { Accept: "application/pdf" },
      });
      if (!res.ok) {
        setError("Could not open the PDF.");
        return;
      }
      const blobUrl = URL.createObjectURL(await res.blob());
      window.open(blobUrl, "_blank");
    } catch {
      setError("Could not open the PDF.");
    }
  };

  const canManage = can("invoices", "create");

  if (authLoading || !isAuthenticated) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Invoices — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-4xl">
          <PageHeader
            title="Invoices"
            icon={<Receipt className="size-6 text-blue-600 dark:text-blue-400" />}
          />

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

          {invoicesQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <Card>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2 font-medium">Invoice</th>
                      <th className="px-4 py-2 font-medium">Client</th>
                      <th className="px-4 py-2 text-right font-medium">Total</th>
                      <th className="px-4 py-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {/* Inline "Generate invoice" row, pinned to the top. */}
                    {canManage &&
                      (showComposer ? (
                        <tr className="border-b bg-muted/10">
                          <td colSpan={4} className="px-4 py-4">
                            <div className="flex flex-wrap items-end gap-3">
                              <div className="space-y-1.5">
                                <Label htmlFor="gen-engagement">Generate from tracked time</Label>
                                <select
                                  id="gen-engagement"
                                  value={genEngagement}
                                  onChange={(e) => setGenEngagement(e.target.value)}
                                  className="h-9 w-64 max-w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                  <option value="">Select an engagement…</option>
                                  {engagements.map((eng) => (
                                    <option key={eng["@id"]} value={eng["@id"]}>
                                      {eng.name}
                                    </option>
                                  ))}
                                </select>
                              </div>
                              <Button
                                size="sm"
                                onClick={() => generate.mutate()}
                                disabled={!genEngagement || generate.isPending}
                              >
                                {generate.isPending ? "Generating…" : "Generate draft"}
                              </Button>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setShowComposer(false)}
                              >
                                Cancel
                              </Button>
                              {engagements.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                  Add an{" "}
                                  <Link href="/engagements" className="text-primary hover:underline">
                                    engagement
                                  </Link>{" "}
                                  first.
                                </p>
                              )}
                            </div>
                          </td>
                        </tr>
                      ) : (
                        <tr className="border-b">
                          <td colSpan={4} className="px-4 py-2">
                            <button
                              type="button"
                              onClick={() => setShowComposer(true)}
                              className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                            >
                              <Plus className="h-4 w-4" /> Generate invoice
                            </button>
                          </td>
                        </tr>
                      ))}

                    {invoices.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">
                          No invoices yet. Use “Generate invoice” to bill tracked time.
                        </td>
                      </tr>
                    ) : (
                      invoices.map((invoice) => {
                        const meta = STATUS_META[invoice.status];
                        return (
                          <tr key={invoice["@id"]} className="border-b align-middle last:border-0">
                            <td className="px-4 py-3">
                              <Link
                                href={`/invoices/${invoice.id}`}
                                className="flex items-center gap-2 font-medium hover:opacity-80"
                              >
                                {invoice.number ?? "Draft"}
                                <span
                                  className={cn(
                                    "rounded px-1.5 py-0.5 text-xs font-medium",
                                    meta.badgeClass,
                                  )}
                                >
                                  {meta.label}
                                </span>
                              </Link>
                            </td>
                            <td className="px-4 py-3 text-muted-foreground">
                              {clientName(invoice.client) || "—"}
                            </td>
                            <td className="px-4 py-3 text-right font-medium tabular-nums">
                              {formatMoney(invoice.total, invoice.currency)}
                            </td>
                            <td className="px-4 py-3 text-right">
                              {canManage && (
                                <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon" aria-label="Invoice actions">
                                      <MoreHorizontal className="size-4" />
                                    </Button>
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="end">
                                    <DropdownMenuItem onClick={() => void openPdf(invoice)}>
                                      View PDF
                                    </DropdownMenuItem>
                                    {invoice.status === "draft" && (
                                      <DropdownMenuItem
                                        onClick={() => act.mutate({ invoice, action: "issue" })}
                                      >
                                        Issue
                                      </DropdownMenuItem>
                                    )}
                                    {invoice.status !== "void" && (
                                      <DropdownMenuItem
                                        onClick={() => act.mutate({ invoice, action: "send" })}
                                      >
                                        Send
                                      </DropdownMenuItem>
                                    )}
                                    {invoice.status !== "paid" && invoice.status !== "void" && (
                                      <DropdownMenuItem
                                        onClick={() => act.mutate({ invoice, action: "mark-paid" })}
                                      >
                                        Mark paid
                                      </DropdownMenuItem>
                                    )}
                                    {invoice.status !== "void" && (
                                      <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                          className="text-destructive"
                                          onClick={() => act.mutate({ invoice, action: "void" })}
                                        >
                                          Void
                                        </DropdownMenuItem>
                                      </>
                                    )}
                                  </DropdownMenuContent>
                                </DropdownMenu>
                              )}
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>
            </Card>
          )}
        </div>
      </div>
    </>
  );
};

export default InvoicesPage;
