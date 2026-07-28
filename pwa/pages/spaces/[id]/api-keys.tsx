import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { Check, Copy, KeyRound, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace, type Space } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  type SpaceRole,
  type SpaceRoleCollection,
  type SpaceRoleRef,
} from "@/lib/roleTypes";
import { formatRelative } from "@/lib/relativeTime";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import PageHeader from "@/components/common/PageHeader";
import ConfirmDialog from "@/components/common/ConfirmDialog";
import { pageTitle } from "@/lib/pageTitle";

interface SpaceApiKey {
  id: string;
  name: string;
  roles: SpaceRoleRef[];
  createdAt: string;
  expiresAt: string | null;
  lastUsedAt: string | null;
}

const SpaceApiKeys = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { can } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const spaceId = typeof id === "string" ? id : null;

  const [space, setSpace] = useState<Space | null>(null);
  const [roles, setRoles] = useState<SpaceRole[]>([]);
  const [keys, setKeys] = useState<SpaceApiKey[]>([]);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [name, setName] = useState("");
  const [selectedRoles, setSelectedRoles] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [createdToken, setCreatedToken] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<SpaceApiKey | null>(null);

  const load = useCallback(async () => {
    if (!spaceId) return;
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}`,
        { credentials: "include", headers: { Accept: "application/ld+json" } },
      );
      if (res.status === 404) {
        setNotFound(true);
        return;
      }
      if (!res.ok) throw new Error("Failed to load space.");
      const data: Space = await res.json();
      setSpace(data);

      const rolesRes = await fetch(
        `${ENTRYPOINT}/space_roles?space=${encodeURIComponent(data["@id"])}`,
        { credentials: "include", headers: { Accept: "application/ld+json" } },
      );
      if (rolesRes.ok) {
        const roleData: SpaceRoleCollection = await rolesRes.json();
        setRoles(roleData.member ?? roleData["hydra:member"] ?? []);
      }

      const keysRes = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/api-keys`,
        { credentials: "include", headers: { Accept: "application/json" } },
      );
      if (keysRes.ok) {
        const keyData: { keys: SpaceApiKey[] } = await keysRes.json();
        setKeys(keyData.keys);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load space.");
    }
  }, [spaceId]);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  useEffect(() => {
    if (isAuthenticated && spaceId) {
      void load();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthenticated, spaceId]);

  const isAdmin =
    !!space &&
    !!user &&
    space.userMemberships.some(
      (m) => m.user.id === user.id && m.role === "admin",
    );

  // Managing keys needs the `api_keys` permission — space admins have it by
  // default; a member can be granted it through a role. (Server enforces; this
  // just gates the UI. `can` reflects the active space, which equals this one
  // in the normal Settings navigation flow.)
  const canManageKeys = isAdmin || can("api_keys", "read");
  const canCreateKeys = isAdmin || can("api_keys", "create");
  const canDeleteKeys = isAdmin || can("api_keys", "delete");

  useEffect(() => {
    if (space && user && !canManageKeys && spaceId) {
      router.replace(`/spaces/${spaceId}`);
    }
  }, [space, user, canManageKeys, spaceId, router]);

  const openCreate = () => {
    setName("");
    setSelectedRoles([]);
    setFormError(null);
    setCreatedToken(null);
    setCopied(false);
    setDialogOpen(true);
  };

  const toggleRole = (iri: string) => {
    setSelectedRoles((prev) =>
      prev.includes(iri) ? prev.filter((r) => r !== iri) : [...prev, iri],
    );
  };

  const create = async () => {
    if (!spaceId || !name.trim()) return;
    setSaving(true);
    setFormError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/api-keys`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ name: name.trim(), roles: selectedRoles }),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || "Failed to create key.");
      setCreatedToken(data.plainToken ?? null);
      await load();
    } catch (err) {
      setFormError(err instanceof Error ? err.message : "Failed to create key.");
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async (key: SpaceApiKey) => {
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId ?? "")}/api-keys/${encodeURIComponent(key.id)}`,
      { method: "DELETE", credentials: "include" },
    );
    if (!res.ok && res.status !== 204) throw new Error("Failed to revoke key.");
    setKeys((prev) => prev.filter((k) => k.id !== key.id));
  };

  if (authLoading || !isAuthenticated || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (notFound) {
    return (
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-md text-center">
          <h1 className="text-xl font-semibold mb-2">Space not found</h1>
          <Button asChild variant="outline">
            <Link href="/spaces">Back to spaces</Link>
          </Button>
        </div>
      </div>
    );
  }

  if (!space) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (!canManageKeys) return null;

  return (
    <>
      <Head>
        <title>{pageTitle("API keys", space.name)}</title>
      </Head>
      <div className="mx-auto max-w-5xl px-4 py-8">
        <PageHeader
          title="API keys"
          icon={<KeyRound className="h-6 w-6 text-cyan-600 dark:text-cyan-400" />}
          subtitle={`Programmatic Bearer keys confined to “${space.name}”, with the roles you assign deciding what they can do.`}
        />

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <p className="mb-4 text-sm text-muted-foreground">
          A key sends <code>Authorization: Bearer …</code> and can only touch
          this space&apos;s content. Define what it can do by assigning{" "}
          <Link
            href={`/spaces/${space.id}/roles`}
            className="text-primary hover:underline"
          >
            roles
          </Link>{" "}
          — a key with no roles can do nothing.
        </p>

        <div className="overflow-hidden rounded-md border bg-card">
          <ul className="divide-y divide-border">
            {keys.length === 0 && (
              <li className="px-4 py-8 text-center text-sm text-muted-foreground">
                No API keys yet.
              </li>
            )}
            {keys.map((key) => (
              <li
                key={key.id}
                className="flex items-center gap-3 px-3 py-2.5"
                data-testid="space-api-key-row"
              >
                <div className="min-w-0 flex-1">
                  <p className="font-medium truncate">{key.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {key.roles.length === 0
                      ? "No roles — inert"
                      : key.roles.map((r) => r.name).join(", ")}
                    {" · "}
                    {key.lastUsedAt
                      ? `used ${formatRelative(key.lastUsedAt)}`
                      : "never used"}
                  </p>
                </div>
                {key.expiresAt && (
                  <Badge variant="outline" className="shrink-0">
                    expires {formatRelative(key.expiresAt)}
                  </Badge>
                )}
                {canDeleteKeys && (
                  <button
                    type="button"
                    onClick={() => setDeleteTarget(key)}
                    aria-label={`Revoke ${key.name}`}
                    className="rounded p-1 text-muted-foreground hover:text-destructive"
                  >
                    <Trash2 className="h-4 w-4" aria-hidden />
                  </button>
                )}
              </li>
            ))}
          </ul>
          {canCreateKeys && (
            <button
              type="button"
              onClick={openCreate}
              className="flex w-full items-center gap-1 border-t px-4 py-2.5 text-sm text-muted-foreground hover:bg-muted/50 hover:text-foreground"
              data-testid="space-api-key-add"
            >
              <KeyRound className="h-3.5 w-3.5" aria-hidden /> New key
            </button>
          )}
        </div>
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-lg">
          {createdToken ? (
            <>
              <DialogHeader>
                <DialogTitle>Key created</DialogTitle>
                <DialogDescription>
                  Copy it now — this is the only time the full key is shown.
                  Madori stores only a hash and can&apos;t display it again.
                </DialogDescription>
              </DialogHeader>
              <div className="flex items-center gap-2 rounded-md border bg-muted/40 p-2">
                <code className="min-w-0 flex-1 truncate text-xs">
                  {createdToken}
                </code>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    void navigator.clipboard.writeText(createdToken);
                    setCopied(true);
                  }}
                >
                  {copied ? (
                    <Check className="h-4 w-4" aria-hidden />
                  ) : (
                    <Copy className="h-4 w-4" aria-hidden />
                  )}
                </Button>
              </div>
              <DialogFooter>
                <Button type="button" onClick={() => setDialogOpen(false)}>
                  Done
                </Button>
              </DialogFooter>
            </>
          ) : (
            <>
              <DialogHeader>
                <DialogTitle>New API key</DialogTitle>
              </DialogHeader>
              <div className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="key-name">Name</Label>
                  <Input
                    id="key-name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="e.g. CI deploy bot"
                    maxLength={80}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Roles</Label>
                  {roles.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                      No roles defined yet — create one on the Roles page
                      first.
                    </p>
                  ) : (
                    <ul className="divide-y rounded-md border">
                      {roles.map((role) => (
                        <li
                          key={role["@id"]}
                          className="flex items-center gap-2.5 px-3 py-2 text-sm"
                        >
                          <Checkbox
                            checked={selectedRoles.includes(role["@id"])}
                            onCheckedChange={() => toggleRole(role["@id"])}
                            aria-label={`Assign ${role.name}`}
                            className="size-4"
                          />
                          <span
                            className="h-2.5 w-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: role.color ?? "#6b7280" }}
                            aria-hidden
                          />
                          <span className="min-w-0 flex-1 truncate">
                            {role.name}
                          </span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
                {formError && (
                  <p className="text-sm text-destructive" role="alert">
                    {formError}
                  </p>
                )}
              </div>
              <DialogFooter>
                <Button
                  type="button"
                  variant="ghost"
                  onClick={() => setDialogOpen(false)}
                  disabled={saving}
                >
                  Cancel
                </Button>
                <Button
                  type="button"
                  onClick={() => void create()}
                  disabled={saving || !name.trim()}
                >
                  {saving ? "Creating…" : "Create key"}
                </Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={`Revoke “${deleteTarget?.name}”?`}
        description="Any integration using this key will immediately stop working. This cannot be undone."
        confirmLabel="Revoke"
        destructive
        onConfirm={async () => {
          if (deleteTarget) await confirmDelete(deleteTarget);
        }}
      />
    </>
  );
};

export default SpaceApiKeys;
