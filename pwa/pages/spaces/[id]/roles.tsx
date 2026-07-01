import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { Pencil, Plus, ShieldCheck, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace, type Space } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { AVATAR_PALETTE } from "@/lib/avatarPalette";
import {
  emptyMatrix,
  isDefaultMemberRole,
  type PermissionMatrix,
  type SpaceRole,
  type SpaceRoleCollection,
} from "@/lib/roleTypes";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import PageHeader from "@/components/common/PageHeader";
import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";
import ConfirmDialog from "@/components/common/ConfirmDialog";
import RolePermissionMatrix from "@/components/spaces/RolePermissionMatrix";

const SpaceRoles = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { refresh } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const spaceId = typeof id === "string" ? id : null;

  const [space, setSpace] = useState<Space | null>(null);
  const [roles, setRoles] = useState<SpaceRole[]>([]);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<SpaceRole | null>(null);
  const [name, setName] = useState("");
  const [color, setColor] = useState<string>(AVATAR_PALETTE[0]);
  const [matrix, setMatrix] = useState<PermissionMatrix>(emptyMatrix());
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<SpaceRole | null>(null);

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

  useEffect(() => {
    if (space && user && !isAdmin && spaceId) {
      router.replace(`/spaces/${spaceId}`);
    }
  }, [space, user, isAdmin, spaceId, router]);

  const openCreate = () => {
    setEditing(null);
    setName("");
    setColor(AVATAR_PALETTE[0]);
    setMatrix(emptyMatrix());
    setFormError(null);
    setDialogOpen(true);
  };

  const openEdit = (role: SpaceRole) => {
    setEditing(role);
    setName(role.name);
    setColor(role.color ?? AVATAR_PALETTE[0]);
    setMatrix({ ...emptyMatrix(), ...role.permissions });
    setFormError(null);
    setDialogOpen(true);
  };

  const save = async () => {
    if (!space || !name.trim()) return;
    setSaving(true);
    setFormError(null);
    try {
      const body = JSON.stringify({
        space: space["@id"],
        name: name.trim(),
        color,
        permissions: matrix,
      });
      const res = editing
        ? await fetch(`${ENTRYPOINT}${editing["@id"]}`, {
            method: "PATCH",
            credentials: "include",
            headers: { "Content-Type": "application/merge-patch+json" },
            body: JSON.stringify({ name: name.trim(), color, permissions: matrix }),
          })
        : await fetch(`${ENTRYPOINT}/space_roles`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/ld+json" },
            body,
          });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.detail || data.error || "Failed to save role.");
      }
      setDialogOpen(false);
      await load();
      await refresh();
    } catch (err) {
      setFormError(err instanceof Error ? err.message : "Failed to save role.");
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async (role: SpaceRole) => {
    const res = await fetch(`${ENTRYPOINT}${role["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok && res.status !== 204) {
      throw new Error("Failed to delete role.");
    }
    setRoles((prev) => prev.filter((r) => r["@id"] !== role["@id"]));
    await refresh();
  };

  const memberCount = (role: SpaceRole): number =>
    space?.userMemberships.filter((m) =>
      (m.roles ?? []).some((r) => r.id === role.id),
    ).length ?? 0;

  // Built-ins first (Member, then Guest), then custom roles by name.
  const builtinRank = (r: SpaceRole): number =>
    r.builtinKey === "member" ? 0 : r.builtinKey === "guest" ? 1 : 2;
  const orderedRoles = [...roles].sort(
    (a, b) => builtinRank(a) - builtinRank(b) || a.name.localeCompare(b.name),
  );

  if (authLoading || !isAuthenticated || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (notFound) {
    return (
      <main className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-md text-center">
          <h1 className="text-xl font-semibold mb-2">Space not found</h1>
          <Button asChild variant="outline">
            <Link href="/spaces">Back to spaces</Link>
          </Button>
        </div>
      </main>
    );
  }

  if (!space) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (!isAdmin) return null;

  return (
    <>
      <Head>
        <title>Permissions · {space.name}</title>
      </Head>
      <main className="mx-auto max-w-3xl px-4 py-8">
        <PageHeader
          title="Permissions"
          icon={<ShieldCheck className="h-6 w-6 text-cyan-600 dark:text-cyan-400" />}
          subtitle={`Roles control what members can do in “${space.name}”.`}
        >
          <Button
            type="button"
            size="sm"
            className="gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white"
            onClick={openCreate}
          >
            <Plus className="h-4 w-4" aria-hidden />
            New role
          </Button>
        </PageHeader>

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <p className="mb-4 text-sm text-muted-foreground">
          Assign roles to members on the{" "}
          <Link
            href={`/spaces/${space.id}/users`}
            className="text-cyan-700 hover:underline dark:text-cyan-400"
          >
            Users
          </Link>{" "}
          page. A member with no role assigned gets the <strong>Member</strong>{" "}
          role; assigning roles restricts them to the union of those roles.
          Admins always have full access. The built-in Member and Guest roles
          can be edited but not deleted.
        </p>

        <Card>
          <CardContent className="pt-6">
            {roles.length === 0 ? (
              <div className="rounded-md border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                No roles yet. Create one to start restricting members.
              </div>
            ) : (
              <ul className="divide-y divide-border rounded-md border">
                {orderedRoles.map((role) => (
                  <li
                    key={role["@id"]}
                    className="flex items-center gap-3 px-3 py-2.5"
                    data-testid="space-role-row"
                  >
                    <span
                      className="h-3 w-3 shrink-0 rounded-full"
                      style={{ backgroundColor: role.color ?? "#6b7280" }}
                      aria-hidden
                    />
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-1.5">
                        <p className="font-medium truncate">{role.name}</p>
                        {isDefaultMemberRole(role) && (
                          <Badge variant="secondary" className="shrink-0">
                            Default
                          </Badge>
                        )}
                        {role.builtinKey === "guest" && (
                          <Badge variant="outline" className="shrink-0">
                            Built-in
                          </Badge>
                        )}
                      </div>
                      <p className="text-xs text-muted-foreground">
                        {memberCount(role)}{" "}
                        {memberCount(role) === 1 ? "member" : "members"}
                      </p>
                    </div>
                    <button
                      type="button"
                      onClick={() => openEdit(role)}
                      aria-label={`Edit ${role.name}`}
                      className="rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                    >
                      <Pencil className="h-4 w-4" aria-hidden />
                    </button>
                    {role.builtinKey === null && (
                      <button
                        type="button"
                        onClick={() => setDeleteTarget(role)}
                        aria-label={`Delete ${role.name}`}
                        className="rounded p-1 text-muted-foreground hover:text-destructive"
                      >
                        <Trash2 className="h-4 w-4" aria-hidden />
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </main>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editing ? "Edit role" : "New role"}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="role-name">Name</Label>
              <Input
                id="role-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Editor, Viewer, Reviewer"
                maxLength={80}
                disabled={editing?.builtinKey != null}
              />
              {editing?.builtinKey != null && (
                <p className="text-xs text-muted-foreground">
                  This is a built-in role — its name is fixed, but you can change
                  what it can do.
                </p>
              )}
            </div>
            <div className="space-y-1.5">
              <Label>Color</Label>
              <ColorSwatchPicker
                value={color}
                onChange={setColor}
                ariaLabel="Role color"
              />
            </div>
            <div className="space-y-1.5">
              <Label>Permissions</Label>
              <RolePermissionMatrix
                value={matrix}
                onChange={setMatrix}
                disabled={saving}
              />
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
              onClick={() => void save()}
              disabled={saving || !name.trim()}
            >
              {saving ? "Saving…" : editing ? "Save" : "Create role"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={`Delete “${deleteTarget?.name}”?`}
        description="Members assigned only this role will fall back to the Member role. This cannot be undone."
        confirmLabel="Delete"
        destructive
        onConfirm={async () => {
          if (deleteTarget) await confirmDelete(deleteTarget);
        }}
      />
    </>
  );
};

export default SpaceRoles;
