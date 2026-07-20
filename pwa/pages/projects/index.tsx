import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Briefcase, Paperclip, Plus, Trash2, X } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGet, apiGetCollection, apiSend, ApiError } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import PageHeader from "@/components/common/PageHeader";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import AttachmentsPanel, { type Attachment } from "@/components/tasks/AttachmentsPanel";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";

const SELECT_CLASS =
  "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring";

const COL_COUNT = 4;

interface ClientRow {
  "@id": string;
  id: string;
  name: string;
  currency?: string | null;
}
interface BoardRow {
  "@id": string;
  id: string;
  title: string;
}
interface CategoryRow {
  "@id"?: string;
  name: string;
  billingRate: number;
  position: number;
  billable: boolean;
}
interface Project {
  "@id": string;
  id: string;
  name: string;
  currency: string | null;
  description: string | null;
  archived: boolean;
  client: string;
  categories: CategoryRow[];
  attachments: Attachment[];
  assignedBoardList: { id: string; title: string }[];
  budgetType: "hours" | "fees" | null;
  budgetAmount: number | null;
  budgetSpent: number | null;
  userRates: { "@id"?: string; user: string; rateAmount: number }[];
}

/** A form category with the rate as a decimal string for editing. */
interface DraftCategory {
  name: string;
  rate: string;
  billable: boolean;
}

/** A person-rate override row (#653) with the rate as a decimal string. */
interface DraftUserRate {
  user: string;
  rate: string;
}

/** A space member as embedded in GET /spaces/{id} (space:read). */
interface SpaceMemberRow {
  user: {
    "@id": string;
    givenName?: string;
    familyName?: string;
    email?: string;
  };
}

const memberLabel = (m: SpaceMemberRow): string => {
  const name = `${m.user.givenName ?? ""} ${m.user.familyName ?? ""}`.trim();
  return name !== "" ? name : m.user.email ?? m.user["@id"];
};

const toMinor = (rate: string): number => Math.round((parseFloat(rate) || 0) * 100);
const toMajor = (minor: number): string => (minor / 100).toFixed(2);
const money = (minor: number, currency: string | null): string =>
  new Intl.NumberFormat(undefined, { style: "currency", currency: currency || "USD" }).format(minor / 100);

const NEW = "new";

/** Budget progress copy: "1.0 / 2.0 h" or "$500.00 / $2,000.00" (#651). */
const budgetLabel = (bp: Project): string => {
  if (!bp.budgetType || !bp.budgetAmount) return "";
  const spent = bp.budgetSpent ?? 0;
  if (bp.budgetType === "hours") {
    return `${(spent / 60).toFixed(1)} / ${(bp.budgetAmount / 60).toFixed(1)} h`;
  }
  return `${money(spent, bp.currency)} / ${money(bp.budgetAmount, bp.currency)}`;
};

/** Small budget progress bar — amber past 80%, red past 100%. */
const BudgetBar = ({ bp }: { bp: Project }) => {
  if (!bp.budgetType || !bp.budgetAmount) return null;
  const pct = Math.round(((bp.budgetSpent ?? 0) / bp.budgetAmount) * 100);
  const width = Math.min(100, pct);
  const tone = pct >= 100 ? "bg-red-500" : pct >= 80 ? "bg-amber-500" : "bg-emerald-500";
  return (
    <div className="mt-1 w-44">
      <div className="h-1.5 overflow-hidden rounded-full bg-muted">
        <div className={`h-full ${tone}`} style={{ width: `${width}%` }} />
      </div>
      <p className="mt-0.5 text-xs text-muted-foreground">
        {budgetLabel(bp)} · {pct}%
      </p>
    </div>
  );
};

const ProjectsPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();

  const spaceIri = activeSpace?.["@id"] ?? null;
  const [projects, setProjects] = useState<Project[]>([]);
  const [clients, setClients] = useState<ClientRow[]>([]);
  const [taskBoards, setTaskBoards] = useState<BoardRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  // Which project the side panel is showing: null (closed) | NEW (create) |
  // an project IRI (view/edit that one).
  const [sheetFor, setSheetFor] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [clientIri, setClientIri] = useState("");
  const [currency, setCurrency] = useState("USD");
  const [description, setDescription] = useState("");
  const [budgetType, setBudgetType] = useState("");
  const [budgetValue, setBudgetValue] = useState("");
  const [cats, setCats] = useState<DraftCategory[]>([{ name: "", rate: "", billable: true }]);
  const [userRates, setUserRates] = useState<DraftUserRate[]>([]);
  const [members, setMembers] = useState<SpaceMemberRow[]>([]);
  const [assigned, setAssigned] = useState<string[]>([]);
  const [attachments, setAttachments] = useState<Attachment[]>([]);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    if (!spaceIri) return;
    setLoading(true);
    setLoadError(null);
    try {
      const q = `?space=${encodeURIComponent(spaceIri)}`;
      const [bps, cls, prj, space] = await Promise.all([
        apiGetCollection<Project>(`/projects${q}`),
        apiGetCollection<ClientRow>(`/clients${q}`),
        apiGetCollection<BoardRow>(`/boards${q}`),
        apiGet<{ userMemberships?: SpaceMemberRow[] }>(spaceIri),
      ]);
      setProjects(bps);
      setClients(cls);
      setTaskBoards(prj);
      setMembers(space.userMemberships ?? []);
    } catch (e) {
      setLoadError(
        e instanceof ApiError && e.status === 403
          ? "Projects are managed by space admins."
          : "Failed to load projects.",
      );
    } finally {
      setLoading(false);
    }
  }, [spaceIri]);

  useEffect(() => {
    if (isAuthenticated && spaceIri) void load();
  }, [isAuthenticated, spaceIri, load]);

  const editing = useMemo(
    () => (sheetFor && sheetFor !== NEW ? projects.find((p) => p["@id"] === sheetFor) ?? null : null),
    [sheetFor, projects],
  );

  const openCreate = () => {
    setName("");
    setClientIri(clients[0]?.["@id"] ?? "");
    setCurrency(clients[0]?.currency ?? "USD");
    setDescription("");
    setBudgetType("");
    setBudgetValue("");
    setCats([{ name: "", rate: "", billable: true }]);
    setUserRates([]);
    setAssigned([]);
    setAttachments([]);
    setFormError(null);
    setSheetFor(NEW);
  };

  const openEdit = (bp: Project) => {
    setName(bp.name);
    setClientIri(bp.client);
    setCurrency(bp.currency ?? "USD");
    setDescription(bp.description ?? "");
    setBudgetType(bp.budgetType ?? "");
    setBudgetValue(
      bp.budgetAmount === null
        ? ""
        : bp.budgetType === "hours"
          ? String(bp.budgetAmount / 60)
          : (bp.budgetAmount / 100).toFixed(2),
    );
    setCats(
      bp.categories.length > 0
        ? bp.categories.map((c) => ({ name: c.name, rate: toMajor(c.billingRate), billable: c.billable }))
        : [{ name: "", rate: "", billable: true }],
    );
    setUserRates(
      (bp.userRates ?? []).map((r) => ({ user: r.user, rate: toMajor(r.rateAmount) })),
    );
    setAssigned(bp.assignedBoardList.map((p) => `/boards/${p.id}`));
    setAttachments(bp.attachments ?? []);
    setFormError(null);
    setSheetFor(bp["@id"]);
  };

  const closeSheet = () => setSheetFor(null);

  const save = async () => {
    if (!name.trim()) {
      setFormError("A name is required.");
      return;
    }
    if (!clientIri) {
      setFormError("A client is required.");
      return;
    }
    setSaving(true);
    setFormError(null);
    const categories = cats
      .filter((c) => c.name.trim())
      .map((c, i) => ({
        name: c.name.trim(),
        billingRate: toMinor(c.rate),
        position: i,
        billable: c.billable,
      }));
    const attachmentIris = attachments.map((a) => a["@id"]);
    const editingIri = sheetFor && sheetFor !== NEW ? sheetFor : null;
    try {
      const shared = {
        client: clientIri,
        name: name.trim(),
        currency,
        description: description.trim() || null,
        categories,
        attachments: attachmentIris,
        // Budget (#651): hours stored as minutes, fees as minor units.
        budgetType: budgetType || null,
        budgetAmount:
          budgetType === ""
            ? null
            : budgetType === "hours"
              ? Math.round((parseFloat(budgetValue) || 0) * 60)
              : Math.round((parseFloat(budgetValue) || 0) * 100),
        // Person rates (#653): per-user billable overrides.
        userRates: userRates
          .filter((r) => r.user && r.rate.trim() !== "")
          .map((r) => ({ user: r.user, rateAmount: toMinor(r.rate) })),
      };
      const saved = editingIri
        ? await apiSend<Project>("PATCH", editingIri, {
            body: shared,
            contentType: "application/merge-patch+json",
          })
        : await apiSend<Project>("POST", "/projects", { body: { space: spaceIri, ...shared } });
      const bpIri = saved?.["@id"] ?? editingIri;
      if (bpIri) {
        await apiSend("PUT", `${bpIri}/boards`, { body: { boards: assigned } });
      }
      closeSheet();
      await load();
    } catch (e) {
      setFormError(e instanceof ApiError ? e.message : "Could not save.");
    } finally {
      setSaving(false);
    }
  };

  const toggleArchive = async (bp: Project) => {
    try {
      await apiSend("PATCH", bp["@id"], {
        body: { archived: !bp.archived },
        contentType: "application/merge-patch+json",
      });
      await load();
    } catch {
      /* surfaced on next load */
    }
  };

  const remove = async (bp: Project) => {
    try {
      await apiSend("DELETE", bp["@id"]);
      closeSheet();
      await load();
    } catch {
      /* surfaced on next load */
    }
  };

  // Hydrate a freshly-uploaded MediaObject into the buffered attachment list so
  // the panel can render it; the link is persisted on Save.
  const onAttach = async (iri: string) => {
    const att = await apiGet<Attachment>(iri, { errorMessage: "Failed to load the upload." });
    setAttachments((prev) => [...prev, att]);
  };
  const onDetach = async (att: Attachment) => {
    setAttachments((prev) => prev.filter((a) => a["@id"] !== att["@id"]));
  };

  const clientName = useMemo(() => {
    const m = new Map<string, string>();
    for (const c of clients) m.set(c["@id"], c.name);
    return m;
  }, [clients]);

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
        <title>Projects — Madori</title>
      </Head>
      <main className="mx-auto max-w-4xl px-6 py-8">
        <PageHeader
          title="Projects"
          icon={<Briefcase className="h-6 w-6 text-orange-600 dark:text-orange-400" />}
          subtitle="A project has a client and a set of categories, each with an hourly rate. Time is tracked against an project + category."
        />

        {loadError && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{loadError}</AlertDescription>
          </Alert>
        )}
        {!loadError && clients.length === 0 && !loading && (
          <Alert className="mb-4">
            <AlertDescription>Add a client first — projects bill to a client.</AlertDescription>
          </Alert>
        )}

        {loading ? (
          <p className="text-muted-foreground">Loading…</p>
        ) : (
          <Card>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="px-4 py-2 font-medium">Name</th>
                    <th className="px-4 py-2 font-medium">Client</th>
                    <th className="px-4 py-2 font-medium">Categories</th>
                    <th className="px-4 py-2 font-medium">Files</th>
                  </tr>
                </thead>
                <tbody>
                  {/* Inline "Add project" row, pinned to the top. */}
                  <tr className="border-b">
                    <td colSpan={COL_COUNT} className="px-4 py-2">
                      <button
                        type="button"
                        onClick={openCreate}
                        disabled={clients.length === 0}
                        className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground disabled:opacity-50"
                      >
                        <Plus className="h-4 w-4" /> Add project
                      </button>
                    </td>
                  </tr>

                  {projects.length === 0 ? (
                    <tr>
                      <td colSpan={COL_COUNT} className="px-4 py-10 text-center text-muted-foreground">
                        No projects yet. Use “Add project” to create one.
                      </td>
                    </tr>
                  ) : (
                    projects.map((bp) => (
                      <tr
                        key={bp["@id"]}
                        onClick={() => openEdit(bp)}
                        className="cursor-pointer border-b align-middle last:border-0 hover:bg-accent/40"
                      >
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            <span className="font-medium">{bp.name}</span>
                            {bp.archived && <Badge variant="secondary">Archived</Badge>}
                          </div>
                          <BudgetBar bp={bp} />
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">
                          {clientName.get(bp.client) ?? "Client"}
                        </td>
                        <td className="px-4 py-3">
                          {bp.categories.length === 0 ? (
                            <span className="text-muted-foreground">—</span>
                          ) : (
                            <div className="flex flex-wrap gap-1.5">
                              {bp.categories.map((c) => (
                                <Badge key={c.name} variant="outline" className="font-normal">
                                  {c.name} · {money(c.billingRate, bp.currency)}
                                  {!c.billable && " · non-billable"}
                                </Badge>
                              ))}
                            </div>
                          )}
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">
                          {bp.attachments.length > 0 ? (
                            <span className="inline-flex items-center gap-1">
                              <Paperclip className="h-3.5 w-3.5" />
                              {bp.attachments.length}
                            </span>
                          ) : (
                            "—"
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </Card>
        )}
      </main>

      <Sheet open={sheetFor !== null} onOpenChange={(o: boolean) => !o && closeSheet()} modal={false}>
        <SheetContent
          side="right"
          className="flex w-full flex-col gap-0 p-0 sm:max-w-lg"
          onInteractOutside={(e: CustomEvent) => {
            const target = e.detail.originalEvent.target as HTMLElement | null;
            if (
              target?.closest(
                '[data-slot="combobox-content"],[data-slot="popover-content"],[data-radix-popper-content-wrapper],.bn-container',
              )
            ) {
              e.preventDefault();
            }
          }}
        >
          <SheetHeader className="border-b px-5 py-4">
            <SheetTitle className="pr-9">
              {sheetFor === NEW ? "New project" : name || "Project"}
            </SheetTitle>
          </SheetHeader>

          <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
            <div className="space-y-1.5">
              <Label htmlFor="bp-name">Name</Label>
              <Input id="bp-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Website redesign" />
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="bp-client">Client</Label>
                <select
                  id="bp-client"
                  value={clientIri}
                  onChange={(e) => {
                    setClientIri(e.target.value);
                    const c = clients.find((x) => x["@id"] === e.target.value);
                    if (c?.currency) setCurrency(c.currency);
                  }}
                  className={SELECT_CLASS}
                >
                  {clients.map((c) => (
                    <option key={c["@id"]} value={c["@id"]}>
                      {c.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="bp-currency">Currency</Label>
                <Input
                  id="bp-currency"
                  value={currency}
                  onChange={(e) => setCurrency(e.target.value.toUpperCase().slice(0, 3))}
                  className="uppercase"
                />
              </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="bp-budget-type">Budget</Label>
                <select
                  id="bp-budget-type"
                  value={budgetType}
                  onChange={(e) => setBudgetType(e.target.value)}
                  className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                >
                  <option value="">No budget</option>
                  <option value="hours">Hours</option>
                  <option value="fees">Fees</option>
                </select>
              </div>
              {budgetType !== "" && (
                <div className="space-y-1.5">
                  <Label htmlFor="bp-budget-value">
                    {budgetType === "hours" ? "Budget hours" : `Budget amount (${currency})`}
                  </Label>
                  <Input
                    id="bp-budget-value"
                    type="number"
                    step={budgetType === "hours" ? "0.5" : "0.01"}
                    min="0"
                    value={budgetValue}
                    onChange={(e) => setBudgetValue(e.target.value)}
                  />
                </div>
              )}
            </div>

            <div className="space-y-1.5">
              <Label>
                Description{" "}
                <span className="font-normal text-muted-foreground">(optional, markdown)</span>
              </Label>
              <MarkdownEditor value={description} onChange={setDescription} ariaLabel="Project description" />
            </div>

            <div className="space-y-2">
              <Label>Categories &amp; rates</Label>
              <p className="text-xs text-muted-foreground">
                Billability is set per category — time tracked on a non-billable category
                is excluded from invoices.
              </p>
              {cats.map((c, i) => (
                <div key={i} className="flex items-center gap-2">
                  <Input
                    value={c.name}
                    onChange={(e) =>
                      setCats((prev) => prev.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))
                    }
                    placeholder="Category (e.g. Development)"
                    className="flex-1"
                  />
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={c.rate}
                    onChange={(e) =>
                      setCats((prev) => prev.map((x, j) => (j === i ? { ...x, rate: e.target.value } : x)))
                    }
                    placeholder="Rate/hr"
                    className="w-24"
                  />
                  <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Switch
                      checked={c.billable}
                      onCheckedChange={(v) =>
                        setCats((prev) => prev.map((x, j) => (j === i ? { ...x, billable: v } : x)))
                      }
                      aria-label={`${c.name || "Category"} is billable`}
                    />
                    Billable
                  </label>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => setCats((prev) => prev.filter((_, j) => j !== i))}
                    aria-label="Remove category"
                  >
                    <X className="h-3.5 w-3.5" />
                  </Button>
                </div>
              ))}
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={() => setCats((prev) => [...prev, { name: "", rate: "", billable: true }])}
              >
                <Plus className="h-3.5 w-3.5" />
                Add category
              </Button>
            </div>

            <div className="space-y-2">
              <Label>Person rates</Label>
              <p className="text-xs text-muted-foreground">
                Optional per-person billable rate overrides — time this person tracks on
                this project bills at their rate instead of the category&apos;s.
              </p>
              {userRates.map((r, i) => (
                <div key={i} className="flex items-center gap-2">
                  <select
                    value={r.user}
                    onChange={(e) =>
                      setUserRates((prev) =>
                        prev.map((row, j) => (j === i ? { ...row, user: e.target.value } : row)),
                      )
                    }
                    className={SELECT_CLASS}
                    aria-label="Member"
                  >
                    <option value="">Member…</option>
                    {members.map((m) => (
                      <option key={m.user["@id"]} value={m.user["@id"]}>
                        {memberLabel(m)}
                      </option>
                    ))}
                  </select>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={r.rate}
                    onChange={(e) =>
                      setUserRates((prev) =>
                        prev.map((row, j) => (j === i ? { ...row, rate: e.target.value } : row)),
                      )
                    }
                    placeholder="Rate / h"
                    className="w-32"
                    aria-label="Hourly rate"
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => setUserRates((prev) => prev.filter((_, j) => j !== i))}
                    aria-label="Remove person rate"
                  >
                    <X className="h-3.5 w-3.5" />
                  </Button>
                </div>
              ))}
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={() => setUserRates((prev) => [...prev, { user: "", rate: "" }])}
              >
                <Plus className="h-3.5 w-3.5" />
                Add person rate
              </Button>
            </div>

            <div className="space-y-2">
              <Label>Contract files</Label>
              <AttachmentsPanel
                taskTitle={name || "this project"}
                attachments={attachments}
                canDeleteAll
                onAttach={onAttach}
                onDetach={onDetach}
              />
            </div>

            {taskBoards.length > 0 && (
              <div className="space-y-2">
                <Label>Assigned boards</Label>
                <div className="max-h-32 space-y-1 overflow-y-auto rounded-md border p-2">
                  {taskBoards.map((p) => {
                    const checked = assigned.includes(p["@id"]);
                    return (
                      <label key={p["@id"]} className="flex items-center gap-2 text-sm">
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={(e) =>
                            setAssigned((prev) =>
                              e.target.checked ? [...prev, p["@id"]] : prev.filter((x) => x !== p["@id"]),
                            )
                          }
                        />
                        {p.title}
                      </label>
                    );
                  })}
                </div>
              </div>
            )}

            {formError && <p className="text-sm text-destructive">{formError}</p>}
          </div>

          <SheetFooter className="flex-row items-center justify-between border-t px-5 py-3">
            <div className="flex items-center gap-1.5">
              {editing && (
                <>
                  <Button variant="ghost" size="sm" onClick={() => void toggleArchive(editing)}>
                    {editing.archived ? "Unarchive" : "Archive"}
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground hover:text-destructive"
                    onClick={() => void remove(editing)}
                    aria-label="Delete project"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                  </Button>
                </>
              )}
            </div>
            <div className="flex items-center gap-2">
              <Button variant="ghost" size="sm" onClick={closeSheet} disabled={saving}>
                Cancel
              </Button>
              <Button size="sm" onClick={() => void save()} disabled={saving}>
                {saving ? "Saving…" : sheetFor === NEW ? "Create" : "Save changes"}
              </Button>
            </div>
          </SheetFooter>
        </SheetContent>
      </Sheet>
    </>
  );
};

export default ProjectsPage;
