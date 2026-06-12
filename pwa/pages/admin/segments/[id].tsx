import type { NextPage } from "next";
import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { fetchWithTimeout } from "@/lib/fetchWithTimeout";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  type Segment,
  type SegmentMembershipMode,
  type SegmentPreview,
  type SegmentPurpose,
  COMMON_ROLES,
  PURPOSE_LABELS,
  PURPOSE_OPTIONS,
  memberFullName,
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
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { ArrowLeft, X } from "lucide-react";

const AdminSegmentDetail: NextPage = () => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");
  const id = typeof router.query.id === "string" ? router.query.id : null;

  const [segment, setSegment] = useState<Segment | null>(null);
  const [preview, setPreview] = useState<SegmentPreview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [notFound, setNotFound] = useState(false);

  // Local editable copy of the role rules + a free-text "add custom role" box.
  const [roles, setRoles] = useState<string[]>([]);
  const [customRole, setCustomRole] = useState("");

  // Add-member form state.
  const [memberEmail, setMemberEmail] = useState("");
  const [memberMode, setMemberMode] = useState<SegmentMembershipMode>("include");
  const [addingMember, setAddingMember] = useState(false);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  const loadPreview = useCallback(async (segmentId: string) => {
    try {
      const res = await fetchWithTimeout(
        `${ENTRYPOINT}/segments/${segmentId}/preview`,
        { credentials: "include" },
      );
      if (res.ok) setPreview(await res.json());
    } catch {
      /* preview is best-effort */
    }
  }, []);

  const load = useCallback(async () => {
    if (!id) return;
    try {
      const res = await fetchWithTimeout(
        `${ENTRYPOINT}/segments/${encodeURIComponent(id)}`,
        {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        },
      );
      if (res.status === 404) {
        setNotFound(true);
        return;
      }
      if (!res.ok) {
        setError("Couldn't load the segment.");
        return;
      }
      const data: Segment = await res.json();
      setSegment(data);
      setRoles(data.includedRoles ?? []);
      loadPreview(data.id);
    } catch {
      setError("Couldn't load the segment.");
    }
  }, [id, loadPreview]);

  useEffect(() => {
    if (isAdmin && id) load();
  }, [isAdmin, id, load]);

  const patch = useCallback(
    async (body: Record<string, unknown>): Promise<Segment | null> => {
      if (!segment) return null;
      const res = await fetchWithTimeout(`${ENTRYPOINT}/segments/${segment.id}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify(body),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data["hydra:description"] || data.detail || "Save failed.");
      }
      return (await res.json()) as Segment;
    },
    [segment],
  );

  const saveConfig = useCallback(async () => {
    if (!segment) return;
    setSaving(true);
    setError(null);
    setNotice(null);
    try {
      const updated = await patch({
        name: segment.name,
        description: segment.description,
        purpose: segment.purpose,
        enabled: segment.enabled,
        rolloutPercentage: segment.rolloutPercentage,
        includedRoles: roles,
      });
      if (updated) {
        setSegment(updated);
        setRoles(updated.includedRoles ?? []);
        setNotice("Saved.");
        loadPreview(updated.id);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : "Save failed.");
    } finally {
      setSaving(false);
    }
  }, [segment, roles, patch, loadPreview]);

  const toggleRole = (role: string) => {
    setRoles((prev) =>
      prev.includes(role) ? prev.filter((r) => r !== role) : [...prev, role],
    );
  };

  const addCustomRole = () => {
    const role = customRole.trim().toUpperCase();
    if (role && !roles.includes(role)) setRoles((prev) => [...prev, role]);
    setCustomRole("");
  };

  const addMember = useCallback(async () => {
    if (!segment || !memberEmail.trim()) return;
    setAddingMember(true);
    setError(null);
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/segments/${segment.id}/members`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: memberEmail.trim(), mode: memberMode }),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) {
        setError(body.error || "Couldn't add that member.");
        return;
      }
      setMemberEmail("");
      await load();
    } catch {
      setError("Couldn't add that member.");
    } finally {
      setAddingMember(false);
    }
  }, [segment, memberEmail, memberMode, load]);

  const removeMember = useCallback(
    async (userId: string) => {
      if (!segment) return;
      try {
        const res = await fetchWithTimeout(
          `${ENTRYPOINT}/segments/${segment.id}/members/${userId}`,
          { method: "DELETE", credentials: "include" },
        );
        if (!res.ok && res.status !== 204) throw new Error();
        await load();
      } catch {
        setError("Couldn't remove that member.");
      }
    },
    [segment, load],
  );

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
      <div className="min-h-screen flex items-center justify-center bg-muted px-4">
        <div className="text-center">
          <h1 className="text-2xl font-bold mb-2">Access Denied</h1>
          <p className="text-muted-foreground">You need administrator privileges.</p>
        </div>
      </div>
    );
  }

  if (notFound) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center bg-muted px-4 gap-3">
        <h1 className="text-2xl font-bold">Segment not found</h1>
        <Link href="/admin/segments" className="text-sm text-cyan-700 hover:underline">
          Back to segments
        </Link>
      </div>
    );
  }

  if (!segment) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  const reach = preview?.reach;

  return (
    <>
      <Head>
        <title>{segment.name} - Madori Segments</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-3xl mx-auto space-y-6">
          <div>
            <Link
              href="/admin/segments"
              className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-4 w-4" /> Segments
            </Link>
            <div className="mt-2 flex items-center gap-2">
              <h1 className="text-2xl font-bold">{segment.name}</h1>
              <Badge variant="secondary">{PURPOSE_LABELS[segment.purpose]}</Badge>
              {!segment.enabled && <Badge variant="outline">disabled</Badge>}
            </div>
            <p className="text-sm text-muted-foreground">
              <code>{segment.role}</code>
            </p>
          </div>

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          {notice && (
            <Alert>
              <AlertDescription>{notice}</AlertDescription>
            </Alert>
          )}

          {reach && (
            <Card data-testid="segment-reach">
              <CardHeader>
                <CardTitle>Reach</CardTitle>
                <CardDescription>
                  {reach.total} user{reach.total === 1 ? "" : "s"} currently match
                  this segment.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                  <Stat label="Manual" value={reach.manualIncludes} />
                  <Stat label="By role" value={reach.byRole} />
                  <Stat label="By rollout" value={reach.byRollout} />
                  <Stat label="Excluded" value={reach.manualExcludes} />
                </div>
              </CardContent>
            </Card>
          )}

          <Card>
            <CardHeader>
              <CardTitle>Configuration</CardTitle>
              <CardDescription>
                Users match when any rule includes them — manual overrides win.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
              <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                <div>
                  <p className="text-sm font-medium">Enabled</p>
                  <p className="text-xs text-muted-foreground">
                    A disabled segment matches nobody.
                  </p>
                </div>
                <Switch
                  checked={segment.enabled}
                  onCheckedChange={(v) => setSegment({ ...segment, enabled: v })}
                  aria-label="Enabled"
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="name">Name</Label>
                <Input
                  id="name"
                  value={segment.name}
                  onChange={(e) => setSegment({ ...segment, name: e.target.value })}
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="description">Description</Label>
                <Textarea
                  id="description"
                  value={segment.description ?? ""}
                  onChange={(e) =>
                    setSegment({ ...segment, description: e.target.value || null })
                  }
                  placeholder="What is this segment for?"
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="purpose">Purpose</Label>
                <select
                  id="purpose"
                  className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
                  value={segment.purpose}
                  onChange={(e) =>
                    setSegment({ ...segment, purpose: e.target.value as SegmentPurpose })
                  }
                >
                  {PURPOSE_OPTIONS.map((p) => (
                    <option key={p} value={p}>
                      {PURPOSE_LABELS[p]}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="rollout">
                  Rollout: {segment.rolloutPercentage ?? 0}%
                </Label>
                <input
                  id="rollout"
                  type="range"
                  min={0}
                  max={100}
                  value={segment.rolloutPercentage ?? 0}
                  onChange={(e) =>
                    setSegment({
                      ...segment,
                      rolloutPercentage: Number(e.target.value) || null,
                    })
                  }
                  className="w-full accent-cyan-700"
                />
                <p className="text-xs text-muted-foreground">
                  A deterministic share of all users, stable per user — raising it
                  never drops anyone who was already in.
                </p>
              </div>

              <div className="space-y-2">
                <Label>Include by role</Label>
                <div className="flex flex-wrap gap-2">
                  {Array.from(new Set([...COMMON_ROLES, ...roles])).map((role) => (
                    <button
                      key={role}
                      type="button"
                      onClick={() => toggleRole(role)}
                      className={`rounded-full border px-3 py-1 text-xs ${
                        roles.includes(role)
                          ? "border-cyan-700 bg-cyan-700 text-white"
                          : "border-input text-muted-foreground"
                      }`}
                    >
                      {role}
                    </button>
                  ))}
                </div>
                <div className="flex gap-2">
                  <Input
                    value={customRole}
                    onChange={(e) => setCustomRole(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter") {
                        e.preventDefault();
                        addCustomRole();
                      }
                    }}
                    placeholder="ROLE_CUSTOM"
                    className="h-8"
                  />
                  <Button type="button" variant="outline" size="sm" onClick={addCustomRole}>
                    Add role
                  </Button>
                </div>
              </div>

              <div className="flex justify-end">
                <Button onClick={saveConfig} disabled={saving} data-testid="segment-save">
                  {saving ? "Saving…" : "Save changes"}
                </Button>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Manual overrides</CardTitle>
              <CardDescription>
                Force a specific user in or out, regardless of the rules above.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex flex-col sm:flex-row gap-2">
                <Input
                  type="email"
                  placeholder="person@example.com"
                  value={memberEmail}
                  onChange={(e) => setMemberEmail(e.target.value)}
                  className="flex-1"
                />
                <select
                  className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                  value={memberMode}
                  onChange={(e) => setMemberMode(e.target.value as SegmentMembershipMode)}
                >
                  <option value="include">Include</option>
                  <option value="exclude">Exclude</option>
                </select>
                <Button onClick={addMember} disabled={addingMember || !memberEmail.trim()}>
                  {addingMember ? "Adding…" : "Add"}
                </Button>
              </div>

              {segment.memberships.length === 0 ? (
                <p className="text-sm text-muted-foreground">No manual overrides.</p>
              ) : (
                <div className="space-y-2" data-testid="segment-members">
                  {segment.memberships.map((m) => (
                    <div
                      key={m.id}
                      className="flex items-center justify-between gap-3 rounded-md border p-2.5"
                    >
                      <div className="min-w-0 flex items-center gap-2">
                        <Badge variant={m.mode === "exclude" ? "destructive" : "secondary"}>
                          {m.mode}
                        </Badge>
                        <span className="text-sm truncate">
                          {m.user ? memberFullName(m.user) : "Unknown user"}
                        </span>
                      </div>
                      {m.user && (
                        <Button
                          variant="ghost"
                          size="icon"
                          aria-label="Remove override"
                          onClick={() => removeMember(m.user!.id)}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </>
  );
};

const Stat = ({ label, value }: { label: string; value: number }) => (
  <div className="rounded-md border p-3">
    <p className="text-2xl font-semibold">{value}</p>
    <p className="text-xs text-muted-foreground">{label}</p>
  </div>
);

export default AdminSegmentDetail;
