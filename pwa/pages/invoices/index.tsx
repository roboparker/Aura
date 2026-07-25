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

/** Minimal project shape for the "generate from time" picker. */
interface ProjectLite {
  "@id": string;
  name: string;
}

/** One candidate row from the from-time preview endpoint (#644). */
interface PreviewEntry {
  "@id": string;
  id: string;
  description: string | null;
  categoryName: string | null;
  startedAt: string | null;
  hours: number;
  unitAmount: number;
  amount: number;
}

/** One candidate expense from the from-time preview endpoint (#650). */
interface PreviewExpense {
  "@id": string;
  id: string;
  description: string | null;
  category: string | null;
  spentOn: string | null;
  amount: number;
}

interface GenPreview {
  entries: PreviewEntry[];
  expenses: PreviewExpense[];
  count: number;
  subtotal: number;
  currency: string;
}

const InvoicesPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [showComposer, setShowComposer] = useState(false);
  const [genProject, setGenProject] = useState("");
  const [genFrom, setGenFrom] = useState("");
  const [genTo, setGenTo] = useState("");
  const [preview, setPreview] = useState<GenPreview | null>(null);
  const [checked, setChecked] = useState<Set<string>>(new Set());
  const [checkedExpenses, setCheckedExpenses] = useState<Set<string>>(new Set());
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  // Deep link from the uninvoiced report (#666): /invoices?generate={projectId}
  // opens the composer with the project preselected and previews it.
  const [autoPreview, setAutoPreview] = useState(false);
  useEffect(() => {
    if (!router.isReady) return;
    const generateParam = router.query.generate;
    if (typeof generateParam !== "string" || generateParam === "") return;
    setShowComposer(true);
    setGenProject(`/projects/${generateParam}`);
    setAutoPreview(true);
    void router.replace("/invoices", undefined, { shallow: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [router.isReady, router.query.generate]);

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
  // Invoices generate from an *project* (Harvest model): the project
  // carries the client + currency and its unbilled time becomes line items.
  const projectsQuery = useQuery({
    queryKey: ["projects", spaceIri],
    enabled: isAuthenticated && can("invoices", "create"),
    queryFn: () =>
      apiGetCollection<ProjectLite>(
        spaceIri ? `/projects?space=${encodeURIComponent(spaceIri)}` : "/projects",
        { errorMessage: "Failed to load projects." },
      ),
  });
  const invoices = invoicesQuery.data ?? [];
  const projects = projectsQuery.data ?? [];
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["invoices"] });

  // Project + optional inclusive date range — shared by preview and generate.
  const rangeBody = () => ({
    project: genProject,
    ...(genFrom ? { from: genFrom } : {}),
    ...(genTo ? { to: genTo } : {}),
  });

  const resetComposer = () => {
    setGenProject("");
    setGenFrom("");
    setGenTo("");
    setPreview(null);
    setChecked(new Set());
    setCheckedExpenses(new Set());
    setShowComposer(false);
  };

  // Any change to the scope invalidates a previously-loaded preview.
  const clearPreview = () => {
    setPreview(null);
    setChecked(new Set());
    setCheckedExpenses(new Set());
  };

  const loadPreview = useMutation({
    mutationFn: () =>
      apiSend<GenPreview>("POST", "/invoices/from-time-entries", {
        contentType: "application/json",
        errorMessage: "Failed to load unbilled time.",
        body: { ...rangeBody(), preview: true },
      }),
    onSuccess: (res) => {
      setError(null);
      setPreview(res);
      // Everything ticked by default — the list is for *un*ticking.
      setChecked(new Set((res?.entries ?? []).map((e) => e["@id"])));
      setCheckedExpenses(new Set((res?.expenses ?? []).map((x) => x["@id"])));
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Failed to load unbilled time."),
  });

  // Fire the deep-linked preview once the preselected project is in state.
  useEffect(() => {
    if (autoPreview && genProject !== "") {
      setAutoPreview(false);
      loadPreview.mutate();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [autoPreview, genProject]);

  const generate = useMutation({
    mutationFn: () =>
      apiSend<{ id: string; lineItemCount: number }>("POST", "/invoices/from-time-entries", {
        contentType: "application/json",
        errorMessage: "Failed to generate an invoice.",
        body: {
          ...rangeBody(),
          entries: Array.from(checked),
          expenses: Array.from(checkedExpenses),
        },
      }),
    onSuccess: (res) => {
      setError(null);
      const n = res?.lineItemCount ?? 0;
      setNotice(`Draft invoice created from ${n} time entr${n === 1 ? "y" : "ies"}.`);
      resetComposer();
      void refresh();
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Failed to generate an invoice."),
  });

  const toggleChecked = (iri: string) => {
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(iri)) {
        next.delete(iri);
      } else {
        next.add(iri);
      }
      return next;
    });
  };

  const toggleCheckedExpense = (iri: string) => {
    setCheckedExpenses((prev) => {
      const next = new Set(prev);
      if (next.has(iri)) {
        next.delete(iri);
      } else {
        next.add(iri);
      }
      return next;
    });
  };

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

  // Per-invoice opt-out for the automatic late-payment reminder emails (#649).
  const toggleReminders = useMutation({
    mutationFn: ({ invoice, enabled }: { invoice: Invoice; enabled: boolean }) =>
      apiSend<Invoice>("PATCH", invoice["@id"], {
        contentType: "application/merge-patch+json",
        errorMessage: "Failed to update reminders.",
        body: { remindersEnabled: enabled },
      }),
    onSuccess: (_res, { enabled }) => {
      setError(null);
      setNotice(enabled ? "Late-payment reminders resumed." : "Late-payment reminders paused.");
      void refresh();
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Failed to update reminders."),
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
                                <Label htmlFor="gen-project">Generate from tracked time</Label>
                                <select
                                  id="gen-project"
                                  value={genProject}
                                  onChange={(e) => {
                                    setGenProject(e.target.value);
                                    clearPreview();
                                  }}
                                  className="h-9 w-64 max-w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                  <option value="">Select a project…</option>
                                  {projects.map((eng) => (
                                    <option key={eng["@id"]} value={eng["@id"]}>
                                      {eng.name}
                                    </option>
                                  ))}
                                </select>
                              </div>
                              <div className="space-y-1.5">
                                <Label htmlFor="gen-from">From</Label>
                                <input
                                  id="gen-from"
                                  type="date"
                                  value={genFrom}
                                  onChange={(e) => {
                                    setGenFrom(e.target.value);
                                    clearPreview();
                                  }}
                                  className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                />
                              </div>
                              <div className="space-y-1.5">
                                <Label htmlFor="gen-to">To</Label>
                                <input
                                  id="gen-to"
                                  type="date"
                                  value={genTo}
                                  onChange={(e) => {
                                    setGenTo(e.target.value);
                                    clearPreview();
                                  }}
                                  className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                />
                              </div>
                              <Button
                                size="sm"
                                onClick={() => loadPreview.mutate()}
                                disabled={!genProject || loadPreview.isPending}
                              >
                                {loadPreview.isPending ? "Loading…" : "Preview"}
                              </Button>
                              <Button variant="ghost" size="sm" onClick={resetComposer}>
                                Cancel
                              </Button>
                              {projects.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                  Add an{" "}
                                  <Link href="/projects" className="text-primary hover:underline">
                                    project
                                  </Link>{" "}
                                  first.
                                </p>
                              )}
                            </div>

                            {preview &&
                              preview.entries.length === 0 &&
                              preview.expenses.length === 0 && (
                              <p className="mt-3 text-sm text-muted-foreground">
                                No unbilled billable time or expenses
                                {genFrom || genTo ? " in this range" : ""} for this project.
                              </p>
                            )}

                            {preview &&
                              (preview.entries.length > 0 || preview.expenses.length > 0) && (
                              <div className="mt-3 space-y-2">
                                <div className="max-h-64 overflow-y-auto rounded-md border">
                                  <table className="w-full text-sm">
                                    <thead>
                                      <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                        <th className="w-8 px-3 py-1.5" />
                                        <th className="px-3 py-1.5 font-medium">Date</th>
                                        <th className="px-3 py-1.5 font-medium">Description</th>
                                        <th className="px-3 py-1.5 text-right font-medium">Hours</th>
                                        <th className="px-3 py-1.5 text-right font-medium">Amount</th>
                                      </tr>
                                    </thead>
                                    <tbody>
                                      {preview.entries.map((entry) => (
                                        <tr
                                          key={entry["@id"]}
                                          className={cn(
                                            "border-b last:border-b-0",
                                            !checked.has(entry["@id"]) && "opacity-50",
                                          )}
                                        >
                                          <td className="px-3 py-1.5">
                                            <input
                                              type="checkbox"
                                              checked={checked.has(entry["@id"])}
                                              onChange={() => toggleChecked(entry["@id"])}
                                              aria-label="Include this entry"
                                            />
                                          </td>
                                          <td className="whitespace-nowrap px-3 py-1.5">
                                            {entry.startedAt
                                              ? new Date(entry.startedAt).toLocaleDateString()
                                              : "—"}
                                          </td>
                                          <td className="px-3 py-1.5">
                                            {entry.description || "Tracked time"}
                                            {entry.categoryName && (
                                              <span className="ml-1.5 text-xs text-muted-foreground">
                                                {entry.categoryName}
                                              </span>
                                            )}
                                          </td>
                                          <td className="px-3 py-1.5 text-right tabular-nums">
                                            {entry.hours.toFixed(2)}
                                          </td>
                                          <td className="px-3 py-1.5 text-right tabular-nums">
                                            {formatMoney(entry.amount, preview.currency)}
                                          </td>
                                        </tr>
                                      ))}
                                      {/* Unbilled billable expenses ride along (#650). */}
                                      {preview.expenses.length > 0 && (
                                        <tr className="border-b bg-muted/40">
                                          <td
                                            colSpan={5}
                                            className="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                          >
                                            Expenses
                                          </td>
                                        </tr>
                                      )}
                                      {preview.expenses.map((expense) => (
                                        <tr
                                          key={expense["@id"]}
                                          className={cn(
                                            "border-b last:border-b-0",
                                            !checkedExpenses.has(expense["@id"]) && "opacity-50",
                                          )}
                                        >
                                          <td className="px-3 py-1.5">
                                            <input
                                              type="checkbox"
                                              checked={checkedExpenses.has(expense["@id"])}
                                              onChange={() =>
                                                toggleCheckedExpense(expense["@id"])
                                              }
                                              aria-label="Include this expense"
                                            />
                                          </td>
                                          <td className="whitespace-nowrap px-3 py-1.5">
                                            {expense.spentOn
                                              ? new Date(expense.spentOn).toLocaleDateString()
                                              : "—"}
                                          </td>
                                          <td className="px-3 py-1.5">
                                            {expense.description || "Expense"}
                                            {expense.category && (
                                              <span className="ml-1.5 text-xs text-muted-foreground">
                                                {expense.category}
                                              </span>
                                            )}
                                          </td>
                                          <td className="px-3 py-1.5" />
                                          <td className="px-3 py-1.5 text-right tabular-nums">
                                            {formatMoney(expense.amount, preview.currency)}
                                          </td>
                                        </tr>
                                      ))}
                                    </tbody>
                                  </table>
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                  <p className="text-sm text-muted-foreground">
                                    {checked.size + checkedExpenses.size} of{" "}
                                    {preview.entries.length + preview.expenses.length} item
                                    {preview.entries.length + preview.expenses.length === 1
                                      ? ""
                                      : "s"}{" "}
                                    selected ·{" "}
                                    {formatMoney(
                                      preview.entries
                                        .filter((e) => checked.has(e["@id"]))
                                        .reduce((sum, e) => sum + e.amount, 0) +
                                        preview.expenses
                                          .filter((x) => checkedExpenses.has(x["@id"]))
                                          .reduce((sum, x) => sum + x.amount, 0),
                                      preview.currency,
                                    )}
                                  </p>
                                  <Button
                                    size="sm"
                                    onClick={() => generate.mutate()}
                                    disabled={
                                      (checked.size === 0 && checkedExpenses.size === 0) ||
                                      generate.isPending
                                    }
                                  >
                                    {generate.isPending ? "Generating…" : "Generate draft"}
                                  </Button>
                                </div>
                              </div>
                            )}
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
                                    {(invoice.status === "sent" ||
                                      invoice.status === "overdue") && (
                                      <DropdownMenuItem
                                        onClick={() =>
                                          toggleReminders.mutate({
                                            invoice,
                                            enabled: !invoice.remindersEnabled,
                                          })
                                        }
                                      >
                                        {invoice.remindersEnabled
                                          ? "Pause reminders"
                                          : "Resume reminders"}
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
