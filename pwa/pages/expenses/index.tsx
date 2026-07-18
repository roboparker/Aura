import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Paperclip, Pencil, Plus, ReceiptText, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGet, apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { uploadAttachmentFile } from "@/lib/attachments";
import { Expense, ExpenseCategoryRow } from "@/lib/expenseTypes";
import { ProjectOption } from "@/lib/timeEntryTypes";
import { formatMoney } from "@/lib/invoiceTypes";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const MERGE_PATCH = "application/merge-patch+json";

const SELECT_CLASS =
  "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50";

const todayInput = (): string => {
  const now = new Date();
  const pad = (n: number) => n.toString().padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};

/** Download a gated receipt via the authenticated media endpoint. */
const openReceipt = async (receiptIri: string, onError: (m: string) => void) => {
  const id = receiptIri.slice(receiptIri.lastIndexOf("/") + 1);
  try {
    const res = await fetch(`/media-objects/${id}/download`, { credentials: "include" });
    if (!res.ok) {
      onError("Could not open the receipt.");
      return;
    }
    window.open(URL.createObjectURL(await res.blob()), "_blank");
  } catch {
    onError("Could not open the receipt.");
  }
};

/**
 * Expense tracking (#650): inline-add list like /time — members log their
 * own expenses against an project, optionally attach a receipt, and
 * invoice generation pulls the unbilled billable ones (billed = locked).
 */
const ExpensesPage = () => {
  const { isAuthenticated, isLoading: authLoading, user } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [showComposer, setShowComposer] = useState(false);
  const [spentOn, setSpentOn] = useState(todayInput);
  const [projectIri, setProjectIri] = useState("");
  const [category, setCategory] = useState("");
  const [expenseCategoryIri, setExpenseCategoryIri] = useState("");
  const [units, setUnits] = useState("");
  const [description, setDescription] = useState("");
  const [amount, setAmount] = useState("");
  const [billable, setBillable] = useState(true);
  const [receiptFile, setReceiptFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceIri = activeSpace?.["@id"] ?? null;
  const spaceId = activeSpace?.id ?? null;
  const currentUserIri = user ? `/users/${user.id}` : null;

  const expensesQuery = useQuery({
    queryKey: ["expenses", spaceIri],
    enabled: isAuthenticated,
    queryFn: () =>
      apiGetCollection<Expense>(
        spaceIri ? `/expenses?space=${encodeURIComponent(spaceIri)}` : "/expenses",
        { errorMessage: "Failed to load expenses." },
      ),
  });
  const expenses = useMemo(() => expensesQuery.data ?? [], [expensesQuery.data]);
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["expenses"] });

  const projectsQuery = useQuery({
    queryKey: ["billing_project_options", spaceId],
    enabled: isAuthenticated && !!spaceId,
    queryFn: () =>
      apiGet<{ options: ProjectOption[] }>(`/spaces/${spaceId}/project-options`, {
        errorMessage: "Failed to load projects.",
      }).then((r) => r.options ?? []),
  });
  const projects = useMemo(() => projectsQuery.data ?? [], [projectsQuery.data]);
  const noProjects = !projectsQuery.isLoading && projects.length === 0;

  // Managed expense categories (#671): the curated picker; free text remains
  // available when a space hasn't defined any (or via the "Other" choice).
  const categoriesQuery = useQuery({
    queryKey: ["expense_categories", spaceIri],
    enabled: isAuthenticated && !!spaceIri,
    queryFn: () =>
      apiGetCollection<ExpenseCategoryRow>(
        `/expense_categories?space=${encodeURIComponent(spaceIri ?? "")}&archived=false`,
        { errorMessage: "Failed to load expense categories." },
      ),
  });
  const managedCategories = useMemo(
    () => categoriesQuery.data ?? [],
    [categoriesQuery.data],
  );
  const selectedManaged =
    managedCategories.find((c) => c["@id"] === expenseCategoryIri) ?? null;
  const projectName = useMemo(() => {
    const m = new Map<string, string>();
    for (const p of projects) m.set(p["@id"], p.name);
    return m;
  }, [projects]);

  useEffect(() => {
    if (!projectIri && projects.length > 0) setProjectIri(projects[0]["@id"]);
  }, [projects, projectIri]);

  const resetComposer = () => {
    setSpentOn(todayInput());
    setCategory("");
    setExpenseCategoryIri("");
    setUnits("");
    setDescription("");
    setAmount("");
    setBillable(true);
    setReceiptFile(null);
    setShowComposer(false);
  };

  const logExpense = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const amountMinor = Math.round((parseFloat(amount) || 0) * 100);
    if (amountMinor <= 0) {
      setActionError("Enter an amount.");
      return;
    }
    setBusy(true);
    setActionError(null);
    try {
      let receiptIri: string | null = null;
      if (receiptFile) {
        receiptIri = await uploadAttachmentFile(receiptFile);
      }
      await apiSend<Expense>("POST", "/expenses", {
        errorMessage: "Failed to log the expense.",
        body: {
          project: projectIri,
          spentOn,
          ...(expenseCategoryIri
            ? { expenseCategory: expenseCategoryIri }
            : { category: category.trim() || null }),
          description: description.trim() || null,
          amount: amountMinor,
          billable,
          ...(receiptIri ? { receipt: receiptIri } : {}),
          ...(spaceIri ? { space: spaceIri } : {}),
        },
      });
      resetComposer();
      void refresh();
    } catch (e) {
      setActionError(e instanceof Error ? e.message : "Failed to log the expense.");
    } finally {
      setBusy(false);
    }
  };

  const deleteExpense = useMutation({
    mutationFn: (iri: string) =>
      apiSend("DELETE", iri, { errorMessage: "Failed to delete the expense." }),
    onSuccess: () => {
      setActionError(null);
      void refresh();
    },
    onError: (e) =>
      setActionError(e instanceof Error ? e.message : "Failed to delete the expense."),
  });

  const [editingIri, setEditingIri] = useState<string | null>(null);
  const updateExpense = useMutation({
    mutationFn: ({ iri, body }: { iri: string; body: Record<string, unknown> }) =>
      apiSend<Expense>("PATCH", iri, {
        contentType: MERGE_PATCH,
        errorMessage: "Failed to update the expense.",
        body,
      }),
    onSuccess: () => {
      setEditingIri(null);
      setActionError(null);
      void refresh();
    },
    onError: (e) =>
      setActionError(e instanceof Error ? e.message : "Failed to update the expense."),
  });

  const canCreate = can("time_entries", "create");
  const isOwn = (expense: Expense) => !!currentUserIri && expense.user === currentUserIri;
  const canModify = (expense: Expense) =>
    !expense.billedAt && (isOwn(expense) || can("time_entries", "update"));
  const error = actionError || (expensesQuery.isError ? "Failed to load expenses." : null);

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
        <title>Expenses — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-5xl">
          <PageHeader
            title="Expenses"
            icon={<ReceiptText className="size-6 text-amber-600 dark:text-amber-400" />}
          />

          {canCreate && noProjects && (
            <Alert className="mb-4">
              <AlertDescription>
                You need an project to log expenses.{" "}
                <Link href="/projects" className="underline">
                  Set one up
                </Link>{" "}
                (or ask a space admin).
              </AlertDescription>
            </Alert>
          )}

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {expensesQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <Card>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2 font-medium">Date</th>
                      <th className="px-4 py-2 font-medium">Project</th>
                      <th className="px-4 py-2 font-medium">Category</th>
                      <th className="px-4 py-2 font-medium">Description</th>
                      <th className="px-4 py-2 font-medium">Status</th>
                      <th className="px-4 py-2 text-right font-medium">Amount</th>
                      <th className="px-2 py-2" aria-label="Actions" />
                    </tr>
                  </thead>
                  <tbody>
                    {canCreate && !noProjects &&
                      (showComposer ? (
                        <tr className="border-b bg-muted/10">
                          <td colSpan={7} className="px-4 py-4">
                            <form onSubmit={(e) => void logExpense(e)} className="space-y-4">
                              <div className="grid gap-3 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                  <Label htmlFor="ex-date">Date</Label>
                                  <Input
                                    id="ex-date"
                                    type="date"
                                    value={spentOn}
                                    onChange={(e) => setSpentOn(e.target.value)}
                                    required
                                  />
                                </div>
                                <div className="space-y-1.5">
                                  <Label htmlFor="ex-project">Project</Label>
                                  <select
                                    id="ex-project"
                                    value={projectIri}
                                    onChange={(e) => setProjectIri(e.target.value)}
                                    className={SELECT_CLASS}
                                  >
                                    {projects.map((p) => (
                                      <option key={p["@id"]} value={p["@id"]}>
                                        {p.name}
                                      </option>
                                    ))}
                                  </select>
                                </div>
                                <div className="space-y-1.5">
                                  <Label htmlFor="ex-amount">Amount</Label>
                                  <Input
                                    id="ex-amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                    required
                                  />
                                </div>
                              </div>
                              <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                  <Label htmlFor="ex-category">
                                    Category{" "}
                                    <span className="font-normal text-muted-foreground">
                                      (optional)
                                    </span>
                                  </Label>
                                  {managedCategories.length > 0 ? (
                                    <>
                                      <select
                                        id="ex-category"
                                        value={expenseCategoryIri}
                                        onChange={(e) => {
                                          setExpenseCategoryIri(e.target.value);
                                          setUnits("");
                                        }}
                                        className={SELECT_CLASS}
                                      >
                                        <option value="">Other (free text)…</option>
                                        {managedCategories.map((c) => (
                                          <option key={c["@id"]} value={c["@id"]}>
                                            {c.name}
                                            {c.unitAmount != null
                                              ? ` (${(c.unitAmount / 100).toFixed(2)}/unit)`
                                              : ""}
                                          </option>
                                        ))}
                                      </select>
                                      {expenseCategoryIri === "" && (
                                        <Input
                                          value={category}
                                          onChange={(e) => setCategory(e.target.value)}
                                          maxLength={80}
                                          placeholder="Travel, software, materials…"
                                          aria-label="Free-text category"
                                        />
                                      )}
                                      {selectedManaged?.unitAmount != null && (
                                        <Input
                                          type="number"
                                          step="0.1"
                                          min="0"
                                          value={units}
                                          onChange={(e) => {
                                            setUnits(e.target.value);
                                            const u = parseFloat(e.target.value) || 0;
                                            setAmount(
                                              ((u * (selectedManaged.unitAmount ?? 0)) / 100).toFixed(2),
                                            );
                                          }}
                                          placeholder="Units (auto-computes the amount)"
                                          aria-label="Units"
                                        />
                                      )}
                                    </>
                                  ) : (
                                    <Input
                                      id="ex-category"
                                      value={category}
                                      onChange={(e) => setCategory(e.target.value)}
                                      maxLength={80}
                                      placeholder="Travel, software, materials…"
                                    />
                                  )}
                                </div>
                                <div className="space-y-1.5">
                                  <Label htmlFor="ex-desc">
                                    Description{" "}
                                    <span className="font-normal text-muted-foreground">
                                      (optional)
                                    </span>
                                  </Label>
                                  <Input
                                    id="ex-desc"
                                    value={description}
                                    onChange={(e) => setDescription(e.target.value)}
                                    maxLength={1000}
                                  />
                                </div>
                              </div>
                              <div className="flex flex-wrap items-center gap-4">
                                <label className="flex items-center gap-2 text-sm">
                                  <input
                                    type="checkbox"
                                    checked={billable}
                                    onChange={(e) => setBillable(e.target.checked)}
                                  />
                                  Billable
                                </label>
                                <div className="flex items-center gap-2 text-sm">
                                  <Label htmlFor="ex-receipt" className="font-normal">
                                    Receipt
                                  </Label>
                                  <input
                                    id="ex-receipt"
                                    type="file"
                                    accept="image/*,application/pdf"
                                    onChange={(e) =>
                                      setReceiptFile(e.target.files?.[0] ?? null)
                                    }
                                    className="text-sm"
                                  />
                                </div>
                              </div>
                              <div className="flex items-center justify-end gap-2">
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={resetComposer}
                                >
                                  Cancel
                                </Button>
                                <Button type="submit" size="sm" disabled={busy || !projectIri}>
                                  {busy ? "Saving…" : "Log expense"}
                                </Button>
                              </div>
                            </form>
                          </td>
                        </tr>
                      ) : (
                        <tr className="border-b">
                          <td colSpan={7} className="px-4 py-2">
                            <button
                              type="button"
                              onClick={() => setShowComposer(true)}
                              className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                            >
                              <Plus className="h-4 w-4" /> Add expense
                            </button>
                          </td>
                        </tr>
                      ))}

                    {expenses.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-4 py-10 text-center text-muted-foreground">
                          No expenses yet. Use “Add expense” to log one.
                        </td>
                      </tr>
                    ) : (
                      expenses.map((expense) =>
                        editingIri === expense["@id"] ? (
                          <EditExpenseRow
                            key={expense["@id"]}
                            expense={expense}
                            projects={projects}
                            pending={updateExpense.isPending}
                            onCancel={() => setEditingIri(null)}
                            onSave={(body) => updateExpense.mutate({ iri: expense["@id"], body })}
                          />
                        ) : (
                          <tr
                            key={expense["@id"]}
                            className="group border-b align-middle last:border-0"
                          >
                            <td className="whitespace-nowrap px-4 py-2.5 text-muted-foreground">
                              {new Date(expense.spentOn).toLocaleDateString(undefined, {
                                dateStyle: "medium",
                              })}
                            </td>
                            <td className="px-4 py-2.5 text-muted-foreground">
                              {expense.project
                                ? projectName.get(expense.project) ?? "—"
                                : "—"}
                            </td>
                            <td className="px-4 py-2.5">{expense.category ?? "—"}</td>
                            <td className="px-4 py-2.5">
                              <span className="inline-flex items-center gap-1.5">
                                {expense.description?.trim() || (
                                  <span className="text-muted-foreground">—</span>
                                )}
                                {expense.receipt && (
                                  <button
                                    type="button"
                                    onClick={() =>
                                      void openReceipt(expense.receipt ?? "", setActionError)
                                    }
                                    aria-label="Open receipt"
                                    title="Open receipt"
                                    className="text-muted-foreground hover:text-foreground"
                                  >
                                    <Paperclip className="h-3.5 w-3.5" />
                                  </button>
                                )}
                              </span>
                            </td>
                            <td className="px-4 py-2.5">
                              <div className="flex items-center gap-1.5">
                                {!expense.billable && (
                                  <Badge variant="secondary">Non-billable</Badge>
                                )}
                                {expense.billedAt && <Badge variant="secondary">Invoiced</Badge>}
                              </div>
                            </td>
                            <td className="whitespace-nowrap px-4 py-2.5 text-right font-medium tabular-nums">
                              {formatMoney(expense.amount, expense.currency ?? "USD")}
                            </td>
                            <td className="whitespace-nowrap px-2 py-2.5 text-right">
                              {canModify(expense) && (
                                <span className="inline-flex items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
                                  <button
                                    type="button"
                                    onClick={() => setEditingIri(expense["@id"])}
                                    aria-label="Edit expense"
                                    className="rounded p-1 text-muted-foreground hover:text-foreground"
                                  >
                                    <Pencil className="h-3.5 w-3.5" />
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => {
                                      if (window.confirm("Delete this expense?")) {
                                        deleteExpense.mutate(expense["@id"]);
                                      }
                                    }}
                                    aria-label="Delete expense"
                                    className="rounded p-1 text-muted-foreground hover:text-destructive"
                                  >
                                    <Trash2 className="h-3.5 w-3.5" />
                                  </button>
                                </span>
                              )}
                            </td>
                          </tr>
                        ),
                      )
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

/** Inline editor for one expense — local state keyed per row. */
const EditExpenseRow = ({
  expense,
  projects,
  pending,
  onSave,
  onCancel,
}: {
  expense: Expense;
  projects: ProjectOption[];
  pending: boolean;
  onSave: (body: Record<string, unknown>) => void;
  onCancel: () => void;
}) => {
  const [eDate, setEDate] = useState(expense.spentOn.slice(0, 10));
  const [eProject, setEProject] = useState(expense.project ?? "");
  const [eCategory, setECategory] = useState(expense.category ?? "");
  const [eDescription, setEDescription] = useState(expense.description ?? "");
  const [eAmount, setEAmount] = useState((expense.amount / 100).toFixed(2));
  const [eBillable, setEBillable] = useState(expense.billable);

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    onSave({
      spentOn: eDate,
      project: eProject,
      category: eCategory.trim() || null,
      description: eDescription.trim() || null,
      amount: Math.round((parseFloat(eAmount) || 0) * 100),
      billable: eBillable,
    });
  };

  return (
    <tr className="border-b bg-muted/10">
      <td colSpan={7} className="px-4 py-4">
        <form onSubmit={submit} className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-4">
            <div className="space-y-1.5">
              <Label htmlFor="exe-date">Date</Label>
              <Input
                id="exe-date"
                type="date"
                value={eDate}
                onChange={(e) => setEDate(e.target.value)}
                required
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="exe-project">Project</Label>
              <select
                id="exe-project"
                value={eProject}
                onChange={(e) => setEProject(e.target.value)}
                className={SELECT_CLASS}
              >
                {projects.map((p) => (
                  <option key={p["@id"]} value={p["@id"]}>
                    {p.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="exe-category">Category</Label>
              <Input
                id="exe-category"
                value={eCategory}
                onChange={(e) => setECategory(e.target.value)}
                maxLength={80}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="exe-amount">Amount</Label>
              <Input
                id="exe-amount"
                type="number"
                step="0.01"
                min="0"
                value={eAmount}
                onChange={(e) => setEAmount(e.target.value)}
                required
              />
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="exe-desc">Description</Label>
            <Input
              id="exe-desc"
              value={eDescription}
              onChange={(e) => setEDescription(e.target.value)}
              maxLength={1000}
            />
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={eBillable}
              onChange={(e) => setEBillable(e.target.checked)}
            />
            Billable
          </label>
          <div className="flex items-center justify-end gap-2">
            <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
              Cancel
            </Button>
            <Button type="submit" size="sm" disabled={pending || !eProject}>
              {pending ? "Saving…" : "Save changes"}
            </Button>
          </div>
        </form>
      </td>
    </tr>
  );
};

export default ExpensesPage;
