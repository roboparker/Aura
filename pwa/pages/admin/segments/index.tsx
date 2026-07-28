import type { NextPage } from "next";
import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { fetchWithTimeout } from "@/lib/fetchWithTimeout";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  type Segment,
  type SegmentPurpose,
  PURPOSE_LABELS,
  PURPOSE_OPTIONS,
  keyToRole,
  normalizeKey,
  readCollection,
} from "@/lib/segmentTypes";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { MoreHorizontal } from "lucide-react";
import { pageTitle } from "@/lib/pageTitle";

const AdminSegments: NextPage = () => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");

  const [segments, setSegments] = useState<Segment[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);

  const [createOpen, setCreateOpen] = useState(false);
  const [draftKey, setDraftKey] = useState("");
  const [draftName, setDraftName] = useState("");
  const [draftPurpose, setDraftPurpose] = useState<SegmentPurpose>("beta");
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/segments`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) {
        setError("Couldn't load segments.");
        return;
      }
      const data = await res.json();
      setSegments(readCollection<Segment>(data));
    } catch {
      setError("Couldn't load segments.");
    } finally {
      setLoaded(true);
    }
  }, []);

  useEffect(() => {
    if (isAdmin) load();
  }, [isAdmin, load]);

  const previewRole = useMemo(
    () => (draftKey ? keyToRole(normalizeKey(draftKey)) : ""),
    [draftKey],
  );

  const resetDraft = () => {
    setDraftKey("");
    setDraftName("");
    setDraftPurpose("beta");
    setCreateError(null);
  };

  const create = useCallback(async () => {
    const key = normalizeKey(draftKey);
    if (!key) {
      setCreateError("A key is required (e.g. beta-new-editor).");
      return;
    }
    setCreating(true);
    setCreateError(null);
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/segments`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          key,
          name: draftName.trim() || key,
          purpose: draftPurpose,
        }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        setCreateError(
          body["hydra:description"] || body.detail || "Couldn't create the segment.",
        );
        return;
      }
      const created: Segment = await res.json();
      setCreateOpen(false);
      resetDraft();
      router.push(`/admin/segments/${created.id}`);
    } catch {
      setCreateError("Couldn't create the segment.");
    } finally {
      setCreating(false);
    }
  }, [draftKey, draftName, draftPurpose, router]);

  const toggleEnabled = useCallback(async (segment: Segment, next: boolean) => {
    // Optimistic flip; revert on failure.
    setSegments((prev) =>
      prev.map((s) => (s.id === segment.id ? { ...s, enabled: next } : s)),
    );
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/segments/${segment.id}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ enabled: next }),
      });
      if (!res.ok) throw new Error();
    } catch {
      setSegments((prev) =>
        prev.map((s) => (s.id === segment.id ? { ...s, enabled: !next } : s)),
      );
      setError("Couldn't update that segment.");
    }
  }, []);

  const remove = useCallback(async (segment: Segment) => {
    if (!window.confirm(`Delete segment "${segment.name}"? This can't be undone.`)) {
      return;
    }
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/segments/${segment.id}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok && res.status !== 204) throw new Error();
      setSegments((prev) => prev.filter((s) => s.id !== segment.id));
    } catch {
      setError("Couldn't delete that segment.");
    }
  }, []);

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (!isAuthenticated) return null;

  if (!isAdmin) {
    return (
      <>
        <Head>
          <title>{pageTitle("Access Denied")}</title>
        </Head>
        <div className="min-h-screen flex items-center justify-center bg-muted px-4">
          <div className="text-center">
            <h1 className="text-2xl font-bold text-foreground mb-2">Access Denied</h1>
            <p className="text-muted-foreground">
              You need administrator privileges to view this page.
            </p>
          </div>
        </div>
      </>
    );
  }

  return (
    <>
      <Head>
        <title>{pageTitle("Segments", "Admin")}</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="max-w-5xl mx-auto space-y-6">
          <header className="flex items-start justify-between gap-4">
            <div>
              <h1 className="text-2xl font-bold">Segments</h1>
              <p className="text-sm text-muted-foreground">
                Group users for A/B tests, beta features, and gradual rollouts.
                Each segment becomes a role you can gate on
                (<code className="text-xs">ROLE_SEGMENT_…</code>).
              </p>
            </div>
            <Button onClick={() => setCreateOpen(true)} data-testid="segment-new">
              New segment
            </Button>
          </header>

          {error && (
            <Alert variant="destructive" data-testid="segment-error">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {loaded && segments.length === 0 ? (
            <Card>
              <CardHeader>
                <CardTitle>No segments yet</CardTitle>
                <CardDescription>
                  Create your first segment to start rolling a feature out to a
                  percentage of users, a set of roles, or a hand-picked beta
                  group.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <Button onClick={() => setCreateOpen(true)}>Create a segment</Button>
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-2" data-testid="segment-list">
              {segments.map((s) => (
                <Card key={s.id} data-testid="segment-row">
                  <CardContent className="flex items-center justify-between gap-4 py-4">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <Link
                          href={`/admin/segments/${s.id}`}
                          className="font-medium hover:underline truncate"
                        >
                          {s.name}
                        </Link>
                        <Badge variant="secondary">{PURPOSE_LABELS[s.purpose]}</Badge>
                        {!s.enabled && <Badge variant="outline">disabled</Badge>}
                      </div>
                      <p className="text-xs text-muted-foreground truncate">
                        <code>{s.role}</code>
                        {typeof s.rolloutPercentage === "number" &&
                          s.rolloutPercentage > 0 &&
                          ` · ${s.rolloutPercentage}% rollout`}
                        {s.includedRoles.length > 0 &&
                          ` · roles: ${s.includedRoles.join(", ")}`}
                      </p>
                    </div>
                    <div className="flex items-center gap-3 shrink-0">
                      <Switch
                        checked={s.enabled}
                        onCheckedChange={(v) => toggleEnabled(s, v)}
                        aria-label="Enabled"
                      />
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" aria-label="More">
                            <MoreHorizontal className="h-4 w-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem onClick={() => router.push(`/admin/segments/${s.id}`)}>
                            Manage
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            className="text-destructive"
                            onClick={() => remove(s)}
                          >
                            Delete
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </div>
      </div>

      <Dialog
        open={createOpen}
        onOpenChange={(o: boolean) => {
          setCreateOpen(o);
          if (!o) resetDraft();
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>New segment</DialogTitle>
            <DialogDescription>
              The key is a stable handle that maps to a role. You can configure
              rollout %, role rules, and members after creating it.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            {createError && (
              <Alert variant="destructive">
                <AlertDescription>{createError}</AlertDescription>
              </Alert>
            )}
            <div className="space-y-1.5">
              <Label htmlFor="segment-key">Key</Label>
              <Input
                id="segment-key"
                placeholder="beta-new-editor"
                value={draftKey}
                onChange={(e) => setDraftKey(e.target.value)}
                data-testid="segment-key-input"
              />
              {previewRole && (
                <p className="text-xs text-muted-foreground">
                  Role: <code>{previewRole}</code>
                </p>
              )}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="segment-name">Name</Label>
              <Input
                id="segment-name"
                placeholder="New editor beta"
                value={draftName}
                onChange={(e) => setDraftName(e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="segment-purpose">Purpose</Label>
              <select
                id="segment-purpose"
                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
                value={draftPurpose}
                onChange={(e) => setDraftPurpose(e.target.value as SegmentPurpose)}
              >
                {PURPOSE_OPTIONS.map((p) => (
                  <option key={p} value={p}>
                    {PURPOSE_LABELS[p]}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setCreateOpen(false)} disabled={creating}>
              Cancel
            </Button>
            <Button onClick={create} disabled={creating} data-testid="segment-create-submit">
              {creating ? "Creating…" : "Create"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
};

export default AdminSegments;
