import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Briefcase, Plus, Trash2, X } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection, apiSend, ApiError } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

const SELECT_CLASS =
  "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring";

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
  rateAmount: number;
  position: number;
}
interface Engagement {
  "@id": string;
  id: string;
  name: string;
  currency: string | null;
  archived: boolean;
  client: string;
  categories: CategoryRow[];
  assignedProjectList: { id: string; title: string }[];
}

/** A form category with the rate as a decimal string for editing. */
interface DraftCategory {
  name: string;
  rate: string;
}

const toMinor = (rate: string): number => Math.round((parseFloat(rate) || 0) * 100);
const toMajor = (minor: number): string => (minor / 100).toFixed(2);
const money = (minor: number, currency: string | null): string =>
  new Intl.NumberFormat(undefined, { style: "currency", currency: currency || "USD" }).format(minor / 100);

const EngagementsPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();

  const spaceIri = activeSpace?.["@id"] ?? null;
  const [projects, setProjects] = useState<Engagement[]>([]);
  const [clients, setClients] = useState<ClientRow[]>([]);
  const [taskBoards, setTaskBoards] = useState<BoardRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  // Editor dialog state (create + edit share it).
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Engagement | null>(null);
  const [name, setName] = useState("");
  const [clientIri, setClientIri] = useState("");
  const [currency, setCurrency] = useState("USD");
  const [cats, setCats] = useState<DraftCategory[]>([{ name: "", rate: "" }]);
  const [assigned, setAssigned] = useState<string[]>([]);
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
      const [bps, cls, prj] = await Promise.all([
        apiGetCollection<Engagement>(`/engagements${q}`),
        apiGetCollection<ClientRow>(`/clients${q}`),
        apiGetCollection<BoardRow>(`/boards${q}`),
      ]);
      setProjects(bps);
      setClients(cls);
      setTaskBoards(prj);
    } catch (e) {
      setLoadError(
        e instanceof ApiError && e.status === 403
          ? "Engagements are managed by space admins."
          : "Failed to load engagements.",
      );
    } finally {
      setLoading(false);
    }
  }, [spaceIri]);

  useEffect(() => {
    if (isAuthenticated && spaceIri) void load();
  }, [isAuthenticated, spaceIri, load]);

  const openCreate = () => {
    setEditing(null);
    setName("");
    setClientIri(clients[0]?.["@id"] ?? "");
    setCurrency(clients[0]?.currency ?? "USD");
    setCats([{ name: "", rate: "" }]);
    setAssigned([]);
    setFormError(null);
    setOpen(true);
  };

  const openEdit = (bp: Engagement) => {
    setEditing(bp);
    setName(bp.name);
    setClientIri(bp.client);
    setCurrency(bp.currency ?? "USD");
    setCats(
      bp.categories.length > 0
        ? bp.categories.map((c) => ({ name: c.name, rate: toMajor(c.rateAmount) }))
        : [{ name: "", rate: "" }],
    );
    setAssigned(bp.assignedProjectList.map((p) => `/boards/${p.id}`));
    setFormError(null);
    setOpen(true);
  };

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
      .map((c, i) => ({ name: c.name.trim(), rateAmount: toMinor(c.rate), position: i }));
    try {
      const payload = { space: spaceIri, client: clientIri, name: name.trim(), currency, categories };
      const saved = editing
        ? await apiSend<Engagement>("PATCH", editing["@id"], {
            body: { name: name.trim(), client: clientIri, currency, categories },
            contentType: "application/merge-patch+json",
          })
        : await apiSend<Engagement>("POST", "/engagements", { body: payload });
      const bpIri = saved?.["@id"] ?? editing?.["@id"];
      if (bpIri) {
        await apiSend("PUT", `${bpIri}/boards`, { body: { boards: assigned } });
      }
      setOpen(false);
      await load();
    } catch (e) {
      setFormError(e instanceof ApiError ? e.message : "Could not save.");
    } finally {
      setSaving(false);
    }
  };

  const toggleArchive = async (bp: Engagement) => {
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

  const remove = async (bp: Engagement) => {
    try {
      await apiSend("DELETE", bp["@id"]);
      await load();
    } catch {
      /* surfaced on next load */
    }
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
        <title>Engagements — Madori</title>
      </Head>
      <main className="px-6 py-8 max-w-4xl mx-auto">
        <PageHeader
          title="Engagements"
          icon={<Briefcase className="h-6 w-6 text-orange-600 dark:text-orange-400" />}
          subtitle="A engagement has a client and a set of categories, each with an hourly rate. Time is tracked against a project + category."
          count={projects.length}
          actions={
            <Button
              size="sm"
              className="gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white"
              onClick={openCreate}
              disabled={clients.length === 0}
            >
              <Plus className="h-3.5 w-3.5" />
              New engagement
            </Button>
          }
        />

        {loadError && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{loadError}</AlertDescription>
          </Alert>
        )}
        {!loadError && clients.length === 0 && !loading && (
          <Alert className="mb-4">
            <AlertDescription>
              Add a client first — engagements bill to a client.
            </AlertDescription>
          </Alert>
        )}

        {loading ? (
          <p className="text-muted-foreground">Loading…</p>
        ) : projects.length === 0 ? (
          <div className="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground">
            No engagements yet.
          </div>
        ) : (
          <ul className="space-y-3">
            {projects.map((bp) => (
              <li key={bp["@id"]} className="rounded-lg border p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <p className="font-semibold">{bp.name}</p>
                      {bp.archived && <Badge variant="secondary">Archived</Badge>}
                    </div>
                    <p className="text-xs text-muted-foreground">
                      {clientName.get(bp.client) ?? "Client"} ·{" "}
                      {bp.categories.length} categor{bp.categories.length === 1 ? "y" : "ies"}
                      {bp.assignedProjectList.length > 0 &&
                        ` · ${bp.assignedProjectList.length} project${bp.assignedProjectList.length === 1 ? "" : "s"}`}
                    </p>
                    {bp.categories.length > 0 && (
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        {bp.categories.map((c) => (
                          <Badge key={c.name} variant="outline" className="font-normal">
                            {c.name} · {money(c.rateAmount, bp.currency)}
                          </Badge>
                        ))}
                      </div>
                    )}
                  </div>
                  <div className="flex shrink-0 items-center gap-1.5">
                    <Button variant="outline" size="sm" onClick={() => openEdit(bp)}>
                      Edit
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => void toggleArchive(bp)}>
                      {bp.archived ? "Unarchive" : "Archive"}
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="text-muted-foreground hover:text-destructive"
                      onClick={() => void remove(bp)}
                      aria-label="Delete engagement"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </main>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editing ? "Edit engagement" : "New engagement"}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
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

            <div className="space-y-2">
              <Label>Categories &amp; rates</Label>
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
                    className="w-28"
                  />
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
                onClick={() => setCats((prev) => [...prev, { name: "", rate: "" }])}
              >
                <Plus className="h-3.5 w-3.5" />
                Add category
              </Button>
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
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={saving}>
              Cancel
            </Button>
            <Button onClick={() => void save()} disabled={saving}>
              {saving ? "Saving…" : editing ? "Save changes" : "Create"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
};

export default EngagementsPage;
