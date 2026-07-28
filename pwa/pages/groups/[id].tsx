import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Mail, Pencil, Plus, Trash2, UserPlus, X } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { resolveGroupColor } from "@/lib/avatarPalette";
import { formatRelative } from "@/lib/relativeTime";
import { displayName } from "@/lib/userDisplay";
import {
  type Group,
  type GroupMembership,
  type GroupPendingInvite,
  toGroupAvatarUser,
} from "@/lib/groupTypes";
import { cn } from "@/lib/utils";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";
import DeleteGroupDialog from "@/components/groups/DeleteGroupDialog";
import GroupTile from "@/components/groups/GroupTile";
import SpaceTile from "@/components/spaces/SpaceTile";
import UserAvatar from "@/components/user/UserAvatar";
import { pageTitle } from "@/lib/pageTitle";

const formatDate = (iso: string): string => {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleDateString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
};

const GroupDetail = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const { id } = router.query;
  const groupId = typeof id === "string" ? id : null;

  const [group, setGroup] = useState<Group | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [isEditing, setIsEditing] = useState(false);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [editColor, setEditColor] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const [newMemberEmail, setNewMemberEmail] = useState("");
  const [isAddingMember, setIsAddingMember] = useState(false);
  const [memberError, setMemberError] = useState<string | null>(null);
  const [memberInfo, setMemberInfo] = useState<string | null>(null);

  const [pendingInvites, setPendingInvites] = useState<GroupPendingInvite[]>([]);
  const [deleteOpen, setDeleteOpen] = useState(false);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const loadPendingInvites = useCallback(async () => {
    if (!groupId) return;
    try {
      const res = await fetch(
        `${ENTRYPOINT}/groups/${encodeURIComponent(groupId)}/invites`,
        { credentials: "include", headers: { Accept: "application/json" } },
      );
      if (!res.ok) {
        setPendingInvites([]);
        return;
      }
      const data: { invites: GroupPendingInvite[] } = await res.json();
      setPendingInvites(data.invites ?? []);
    } catch {
      setPendingInvites([]);
    }
  }, [groupId]);

  const load = useCallback(async () => {
    if (!groupId) return;
    setError(null);
    setIsLoading(true);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/groups/${encodeURIComponent(groupId)}`,
        { credentials: "include", headers: { Accept: "application/ld+json" } },
      );
      if (res.status === 404 || res.status === 403) {
        setNotFound(true);
        return;
      }
      if (!res.ok) throw new Error("Failed to load group.");
      const data: Group = await res.json();
      setGroup(data);
      setEditTitle(data.title);
      setEditDescription(data.description ?? "");
      setEditColor(data.color);
      await loadPendingInvites();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load group.");
    } finally {
      setIsLoading(false);
    }
  }, [groupId, loadPendingInvites]);

  useEffect(() => {
    if (isAuthenticated && groupId) void load();
  }, [isAuthenticated, groupId, load]);

  // Surface a best-effort invite failure handed over from /groups/new.
  useEffect(() => {
    const e = router.query.inviteError;
    if (typeof e === "string" && e) {
      setMemberError(`Couldn't reach: ${e.split(",").join(", ")}.`);
    }
  }, [router.query.inviteError]);

  // Any member of the group's space can manage it (#groups-space); the
  // viewer reached this page through the space-scoped access extension, so
  // they may edit/delete/manage. "Leave" only applies if they're in the roster.
  const isMember = !!group && !!user && group.memberships.some((m) => m.user.id === user.id);

  const handleSaveEdit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!group || !editTitle.trim()) return;
    setIsSaving(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${group["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          title: editTitle.trim(),
          description: editDescription.trim() || null,
          color: editColor,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to update group.",
        );
      }
      setIsEditing(false);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update group.");
    } finally {
      setIsSaving(false);
    }
  };

  const handleAddMember = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!group || !newMemberEmail.trim()) return;
    const submitted = newMemberEmail.trim();
    setIsAddingMember(true);
    setMemberError(null);
    setMemberInfo(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/groups/${group.id}/members`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: submitted }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to add member.");
      }
      const data: { status?: "added" | "invited" } = await res
        .json()
        .catch(() => ({}));
      if (data.status === "invited") {
        setMemberInfo(
          `${submitted} doesn't have an Madori account yet — we sent them an invitation email.`,
        );
      }
      setNewMemberEmail("");
      await load();
    } catch (err) {
      setMemberError(err instanceof Error ? err.message : "Failed to add member.");
    } finally {
      setIsAddingMember(false);
    }
  };

  const handleRemoveMember = async (membership: GroupMembership) => {
    if (!group) return;
    if (!window.confirm(`Remove ${displayName(membership.user)} from this group?`)) {
      return;
    }
    setMemberError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/groups/${group.id}/members/${membership.user.id}`,
        { method: "DELETE", credentials: "include" },
      );
      if (!res.ok && res.status !== 204) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to remove member.");
      }
      await load();
    } catch (err) {
      setMemberError(err instanceof Error ? err.message : "Failed to remove member.");
    }
  };

  const handleResendInvite = async (invite: GroupPendingInvite) => {
    if (!group) return;
    setMemberError(null);
    setMemberInfo(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/groups/${group.id}/invites/${invite.id}/resend`,
        { method: "POST", credentials: "include" },
      );
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to resend invite.");
      }
      setMemberInfo(`Invitation re-sent to ${invite.email}.`);
      await loadPendingInvites();
    } catch (err) {
      setMemberError(err instanceof Error ? err.message : "Failed to resend invite.");
    }
  };

  const handleRevokeInvite = async (invite: GroupPendingInvite) => {
    if (!group) return;
    if (!window.confirm(`Revoke the invitation sent to ${invite.email}?`)) return;
    setMemberError(null);
    setMemberInfo(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/groups/${group.id}/invites/${invite.id}`,
        { method: "DELETE", credentials: "include" },
      );
      if (!res.ok && res.status !== 204) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to revoke invite.");
      }
      await loadPendingInvites();
    } catch (err) {
      setMemberError(err instanceof Error ? err.message : "Failed to revoke invite.");
    }
  };

  const handleLeave = async () => {
    if (!group) return;
    if (!window.confirm(`Leave "${group.title}"?`)) return;
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/groups/${group.id}/leave`, {
        method: "POST",
        credentials: "include",
      });
      if (!res.ok && res.status !== 204) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to leave group.");
      }
      await router.push("/groups");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to leave group.");
    }
  };

  if (authLoading || !isAuthenticated || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (notFound) {
    return (
      <div className="px-4 py-12 max-w-5xl mx-auto">
        <Card>
          <CardContent className="pt-6">
            <h1 className="text-xl font-bold mb-2">Group not found</h1>
            <p className="text-muted-foreground mb-4">
              It may have been deleted, or you may not belong to its space.
            </p>
            <Link href="/groups" className="text-primary font-medium">
              Back to groups
            </Link>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>{pageTitle(group?.title ?? "Group")}</title>
      </Head>
      <div className="px-6 py-8 max-w-6xl mx-auto">
        {isLoading || !group ? (
          <p className="text-muted-foreground">Loading…</p>
        ) : (
          <>
            {/* Header */}
            <div className="flex flex-wrap items-start gap-4 mb-8">
              <GroupTile color={resolveGroupColor(group)} size="lg" />
              <div className="min-w-0 flex-1">
                <h1 className="text-2xl font-bold truncate">{group.title}</h1>
                <p className="mt-1 text-sm text-muted-foreground">
                  {group.memberships.length}{" "}
                  {group.memberships.length === 1 ? "member" : "members"}
                  {group.spaceSummary && (
                    <>
                      {" · in "}
                      <Link
                        href={`/spaces/${group.spaceSummary.id}`}
                        className="font-medium text-foreground hover:underline"
                      >
                        {group.spaceSummary.name}
                      </Link>
                    </>
                  )}
                  {" · "}
                  <span className="font-mono">g-{group.slug}</span>
                </p>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="gap-1.5"
                  onClick={() => setIsEditing((v) => !v)}
                >
                  <Pencil className="h-3.5 w-3.5" aria-hidden />
                  Edit
                </Button>
                {isMember && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="gap-1.5"
                    onClick={handleLeave}
                  >
                    <X className="h-3.5 w-3.5" aria-hidden />
                    Leave
                  </Button>
                )}
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="gap-1.5 text-destructive hover:text-destructive"
                  onClick={() => setDeleteOpen(true)}
                >
                  <Trash2 className="h-3.5 w-3.5" aria-hidden />
                  Delete
                </Button>
              </div>
            </div>

            {error && (
              <Alert variant="destructive" className="mb-4">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
              {/* Main column */}
              <div className="lg:col-span-2 space-y-6">
                {isEditing && (
                  <Card>
                    <CardContent className="pt-6">
                      <form onSubmit={handleSaveEdit} className="space-y-4">
                        <div className="space-y-1.5">
                          <Label htmlFor="edit-title">Name</Label>
                          <Input
                            id="edit-title"
                            value={editTitle}
                            onChange={(e) => setEditTitle(e.target.value)}
                            required
                            maxLength={255}
                          />
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="edit-description">Description</Label>
                          <MarkdownEditor
                            id="edit-description"
                            ariaLabel="Description"
                            value={editDescription}
                            onChange={setEditDescription}
                          />
                        </div>
                        <div className="space-y-1.5">
                          <Label>Color</Label>
                          <ColorSwatchPicker
                            value={editColor ?? resolveGroupColor(group)}
                            onChange={(c) => setEditColor(c)}
                            ariaLabel="Group color"
                          />
                        </div>
                        <div className="flex gap-2">
                          <Button type="submit" size="sm" disabled={isSaving}>
                            {isSaving ? "Saving…" : "Save changes"}
                          </Button>
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                              setIsEditing(false);
                              setEditTitle(group.title);
                              setEditDescription(group.description ?? "");
                              setEditColor(group.color);
                            }}
                          >
                            Cancel
                          </Button>
                        </div>
                      </form>
                    </CardContent>
                  </Card>
                )}

                {/* Members */}
                <Card>
                  <CardContent className="pt-6">
                    <div className="flex items-center justify-between mb-1">
                      <h2 className="font-semibold">
                        Members{" "}
                        <span className="text-muted-foreground font-normal">
                          {group.memberships.length}
                        </span>
                      </h2>
                    </div>
                    <p className="text-xs text-muted-foreground mb-4">
                      Everyone in this group has access to its space.
                    </p>

                    <div className="overflow-hidden rounded-md border">
                      <div className="hidden sm:grid grid-cols-[1fr_120px_40px] gap-3 border-b bg-muted/40 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        <span>Member</span>
                        <span>Joined</span>
                        <span className="sr-only">Actions</span>
                      </div>
                      <ul className="divide-y" data-testid="member-list">
                        {group.memberships.map((m) => {
                          const isSelf = m.user.id === user.id;
                          return (
                            <li
                              key={m["@id"]}
                              className="grid grid-cols-1 sm:grid-cols-[1fr_120px_40px] items-center gap-3 px-3 py-2.5"
                              data-testid="member-row"
                            >
                              <div className="flex items-center gap-2.5 min-w-0">
                                <UserAvatar
                                  user={toGroupAvatarUser(m.user)}
                                  size="sm"
                                />
                                <div className="min-w-0">
                                  <div className="flex items-center gap-1.5">
                                    <span className="text-sm font-medium truncate">
                                      {displayName(m.user)}
                                    </span>
                                    {isSelf && (
                                      <span className="text-xs uppercase tracking-wide text-muted-foreground">
                                        you
                                      </span>
                                    )}
                                  </div>
                                  <span className="block text-xs text-muted-foreground truncate">
                                    {m.user.email}
                                  </span>
                                </div>
                              </div>
                              <div className="text-xs text-muted-foreground">
                                {formatDate(m.joinedAt)}
                              </div>
                              <div className="flex justify-end">
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="icon"
                                  className="h-7 w-7 text-muted-foreground hover:text-destructive"
                                  aria-label={`Remove ${displayName(m.user)}`}
                                  onClick={() => handleRemoveMember(m)}
                                >
                                  <X className="h-4 w-4" />
                                </Button>
                              </div>
                            </li>
                          );
                        })}
                      </ul>
                    </div>

                    <form
                      onSubmit={handleAddMember}
                      className="mt-3 flex items-center gap-2"
                      data-testid="add-member-form"
                    >
                      <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                        <UserPlus className="h-4 w-4" aria-hidden />
                      </span>
                      <Input
                        type="email"
                        value={newMemberEmail}
                        onChange={(e) => setNewMemberEmail(e.target.value)}
                        placeholder="member@example.com"
                        aria-label="New member email"
                        required
                        className="flex-1"
                      />
                      <Button
                        type="submit"
                        size="sm"
                        className="gap-1.5"
                        disabled={isAddingMember || !newMemberEmail.trim()}
                      >
                        <Plus className="h-3.5 w-3.5" aria-hidden />
                        {isAddingMember ? "Adding…" : "Add member"}
                      </Button>
                    </form>

                    {memberError && (
                      <p role="alert" className="mt-2 text-sm text-destructive">
                        {memberError}
                      </p>
                    )}
                    {memberInfo && (
                      <Alert className="mt-2" role="status" data-testid="member-info">
                        <AlertDescription>{memberInfo}</AlertDescription>
                      </Alert>
                    )}
                  </CardContent>
                </Card>

                {/* Pending invitations */}
                {pendingInvites.length > 0 && (
                  <Card data-testid="pending-invites-section">
                    <CardContent className="pt-6">
                      <h2 className="font-semibold mb-1">
                        Pending invitations{" "}
                        <span className="text-muted-foreground font-normal">
                          {pendingInvites.length}
                        </span>
                      </h2>
                      <p className="text-xs text-muted-foreground mb-4">
                        Invites that haven&apos;t been accepted yet. New members
                        are added to this group when they accept.
                      </p>
                      <ul className="space-y-2">
                        {pendingInvites.map((invite) => (
                          <li
                            key={invite.id}
                            className="flex items-center gap-3 rounded-md border px-3 py-2"
                            data-testid="pending-invite-row"
                          >
                            <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                              <Mail className="h-4 w-4" aria-hidden />
                            </span>
                            <div className="min-w-0 flex-1">
                              <p className="text-sm truncate">{invite.email}</p>
                              <p className="text-xs text-muted-foreground">
                                sent {formatRelative(invite.createdAt)} · expires{" "}
                                {formatRelative(invite.expiresAt)}
                              </p>
                            </div>
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              onClick={() => handleResendInvite(invite)}
                            >
                              Resend
                            </Button>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="text-destructive hover:text-destructive"
                              onClick={() => handleRevokeInvite(invite)}
                            >
                              Revoke
                            </Button>
                          </li>
                        ))}
                      </ul>
                    </CardContent>
                  </Card>
                )}

                {/* Danger zone */}
                <Card className="border-destructive/30">
                  <CardContent className="pt-6">
                    <h2 className="font-semibold mb-1">Danger zone</h2>
                    <p className="text-xs text-muted-foreground mb-4">
                      Deleting the group removes it for everyone — its members
                      lose the access this group grants to the space.
                    </p>
                    <div className="flex items-center justify-between gap-3 rounded-md border border-destructive/40 px-3 py-3">
                      <div className="min-w-0">
                        <p className="text-sm font-medium flex items-center gap-1.5 text-destructive">
                          <Trash2 className="h-3.5 w-3.5" aria-hidden />
                          Delete group
                        </p>
                        <p className="text-xs text-muted-foreground">
                          This can&apos;t be undone.
                        </p>
                      </div>
                      <Button
                        type="button"
                        variant="destructive"
                        size="sm"
                        className="gap-1.5"
                        onClick={() => setDeleteOpen(true)}
                      >
                        <Trash2 className="h-3.5 w-3.5" aria-hidden />
                        Delete group
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              </div>

              {/* Rail */}
              <div className="space-y-6">
                <Card>
                  <CardContent className="pt-6">
                    <div className="flex items-center justify-between mb-3">
                      <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        About
                      </h2>
                      {!isEditing && (
                        <button
                          type="button"
                          onClick={() => setIsEditing(true)}
                          className="text-xs text-emerald-600 hover:underline"
                        >
                          Edit
                        </button>
                      )}
                    </div>
                    {group.description ? (
                      <MarkdownView source={group.description} />
                    ) : (
                      <p className="text-sm text-muted-foreground">
                        No description yet.
                      </p>
                    )}
                  </CardContent>
                </Card>

                {group.spaceSummary && (
                  <Card>
                    <CardContent className="pt-6">
                      <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Belongs to
                      </h2>
                      <Link
                        href={`/spaces/${group.spaceSummary.id}`}
                        className="flex items-center gap-2.5 no-underline group"
                      >
                        <SpaceTile
                          name={group.spaceSummary.name}
                          color={group.spaceSummary.color ?? "#334155"}
                          isPersonal={group.spaceSummary.isPersonal}
                          size="sm"
                        />
                        <p className="text-sm font-medium truncate group-hover:underline">
                          {group.spaceSummary.name}
                        </p>
                      </Link>
                    </CardContent>
                  </Card>
                )}

                <Card>
                  <CardContent className="pt-6">
                    <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      At a glance
                    </h2>
                    <dl className="space-y-2 text-sm">
                      <GlanceRow label="id" value={`g-${group.slug}`} mono />
                      <GlanceRow label="members" value={`${group.memberships.length}`} />
                      {group.spaceSummary && (
                        <GlanceRow label="space" value={group.spaceSummary.name} />
                      )}
                      <GlanceRow label="created" value={formatDate(group.createdOn)} />
                      <GlanceRow
                        label="updated"
                        value={formatRelative(group.updatedAt)}
                      />
                    </dl>
                  </CardContent>
                </Card>
              </div>
            </div>

            <DeleteGroupDialog
              open={deleteOpen}
              onOpenChange={setDeleteOpen}
              group={group}
              onDeleted={() => {
                setDeleteOpen(false);
                void router.push("/groups");
              }}
            />
          </>
        )}
      </div>
    </>
  );
};

const GlanceRow = ({
  label,
  value,
  mono,
}: {
  label: string;
  value: string;
  mono?: boolean;
}) => (
  <div className="flex items-center justify-between gap-3">
    <dt className="font-mono text-xs text-muted-foreground">{label}</dt>
    <dd className={cn("truncate", mono && "font-mono text-xs")}>{value}</dd>
  </div>
);

export default GroupDetail;
