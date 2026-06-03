import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Archive, Mail, Pencil, Search, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import {
  useActiveSpace,
  type Space,
  type SpaceMember,
} from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { resolveSpaceColor } from "@/lib/avatarPalette";
import { formatRelative } from "@/lib/relativeTime";
import { displayName } from "@/lib/userDisplay";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import EmailChipInput from "@/components/common/EmailChipInput";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";
import SpaceTile from "@/components/spaces/SpaceTile";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";

const MAX_INVITES = 25;

interface PendingInvite {
  id: string;
  email: string;
  invitedBy: string;
  role: string;
  createdAt: string;
  expiresAt: string;
}

type RoleFilter = "all" | "admin" | "member";

/** Absolute, human date — the header/at-a-glance want "Nov 14, 2024", not "2 days ago". */
const formatDate = (iso: string): string =>
  new Date(iso).toLocaleDateString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
  });

/** SpaceMember.personalizedColor is optional; UserAvatar needs a string. */
const toAvatarUser = (m: SpaceMember): AvatarUser => ({
  ...m,
  personalizedColor: m.personalizedColor ?? "#64748b",
});

/**
 * Admin-only settings surface for a space (`/spaces/{id}/settings`).
 * Mirrors the management blocks that used to live inline on the space
 * detail page, restructured into a dedicated two-column page: members,
 * group memberships, invites, and the danger zone in the main column;
 * about + at-a-glance facts in the rail.
 *
 * First cut wires only what the API already supports — add member /
 * invite, list + revoke invites, edit name/description/color, and
 * delete. Controls that need new endpoints (change a member's role,
 * remove a member, attach a group, resend an invite, archive the
 * space) are rendered read-only or marked unavailable rather than
 * faked.
 */
const SpaceSettings = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { refresh } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const spaceId = typeof id === "string" ? id : null;

  const [space, setSpace] = useState<Space | null>(null);
  const [invites, setInvites] = useState<PendingInvite[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Edit dialog (name / description / color)
  const [editOpen, setEditOpen] = useState(false);
  const [editName, setEditName] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [editColor, setEditColor] = useState<string | null>(null);
  const [isSavingMeta, setIsSavingMeta] = useState(false);

  // Invite-by-email
  const [inviteEmails, setInviteEmails] = useState<string[]>([]);
  const [isSendingInvites, setIsSendingInvites] = useState(false);
  const [inviteMessage, setInviteMessage] = useState<{
    text: string;
    kind: "success" | "error";
  } | null>(null);

  // Member list filters (client-side)
  const [memberQuery, setMemberQuery] = useState("");
  const [roleFilter, setRoleFilter] = useState<RoleFilter>("all");

  const load = useCallback(async () => {
    if (!spaceId) return;
    setError(null);
    setIsLoading(true);
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
      setEditName(data.name);
      setEditDescription(data.description ?? "");
      setEditColor(data.color);

      const isMembershipAdmin = data.userMemberships.some(
        (m) => user && m.user.id === user.id && m.role === "admin",
      );
      if (isMembershipAdmin) {
        const inviteRes = await fetch(
          `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/invites`,
          { credentials: "include", headers: { Accept: "application/json" } },
        );
        if (inviteRes.ok) {
          const inviteData: { invites: PendingInvite[] } = await inviteRes.json();
          setInvites(inviteData.invites);
        }
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load space.");
    } finally {
      setIsLoading(false);
    }
  }, [spaceId, user]);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  useEffect(() => {
    if (isAuthenticated && spaceId) {
      void load();
    }
  }, [isAuthenticated, spaceId, load]);

  const isAdmin =
    !!space &&
    !!user &&
    space.userMemberships.some(
      (m) => m.user.id === user.id && m.role === "admin",
    );

  // Settings is an admin surface — non-admins get bounced to the
  // space detail page once we know they aren't an admin.
  useEffect(() => {
    if (space && user && !isAdmin && spaceId) {
      router.replace(`/spaces/${spaceId}`);
    }
  }, [space, user, isAdmin, spaceId, router]);

  const handleSaveMeta = async () => {
    if (!space || !editName.trim()) return;
    setIsSavingMeta(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          name: editName.trim(),
          description: editDescription.trim() || null,
          color: editColor,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.detail || data["hydra:description"] || "Failed to save changes.",
        );
      }
      setEditOpen(false);
      await load();
      await refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save changes.");
    } finally {
      setIsSavingMeta(false);
    }
  };

  const handleSendInvites = async () => {
    if (!space || inviteEmails.length === 0) return;
    setIsSendingInvites(true);
    setInviteMessage(null);
    let added = 0;
    let invited = 0;
    const failures: string[] = [];
    for (const email of inviteEmails) {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/members`,
          {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email }),
          },
        );
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          failures.push(`${email} (${data.error ?? "failed"})`);
          continue;
        }
        if (data.status === "added") added += 1;
        else invited += 1;
      } catch {
        failures.push(`${email} (network error)`);
      }
    }
    const parts: string[] = [];
    if (added) parts.push(`${added} added`);
    if (invited) parts.push(`${invited} invited`);
    setInviteMessage({
      text: failures.length
        ? `${parts.join(", ") || "Nothing sent"} · couldn't reach: ${failures.join(", ")}`
        : parts.join(", ") || "Done",
      kind: failures.length ? "error" : "success",
    });
    setInviteEmails([]);
    await load();
    await refresh();
    setIsSendingInvites(false);
  };

  const handleRevokeInvite = async (invite: PendingInvite) => {
    if (!space) return;
    if (
      !window.confirm(
        `Revoke the pending invitation for ${invite.email}? Their existing sign-up link stops working.`,
      )
    ) {
      return;
    }
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/invites/${encodeURIComponent(invite.id)}`,
      { method: "DELETE", credentials: "include" },
    );
    if (!res.ok) {
      setError("Failed to revoke invitation.");
      return;
    }
    setInvites((prev) => prev.filter((i) => i.id !== invite.id));
  };

  const handleDeleteSpace = async () => {
    if (!space) return;
    if (
      !window.confirm(
        `Delete "${space.name}"? Every project, discussion, and custom field in this space goes with it. This can't be undone.`,
      )
    ) {
      return;
    }
    const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      setError(data.detail || "Failed to delete space.");
      return;
    }
    await refresh();
    router.push("/spaces");
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
      <main className="min-h-screen bg-muted px-4 py-12">
        <div className="mx-auto max-w-md text-center">
          <h1 className="text-xl font-semibold mb-2">Space not found</h1>
          <p className="text-muted-foreground mb-4">
            It may have been deleted, or you no longer have access.
          </p>
          <Button asChild variant="outline">
            <Link href="/spaces">Back to spaces</Link>
          </Button>
        </div>
      </main>
    );
  }

  if (isLoading || !space) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  // Non-admins are redirected by the effect above; render nothing in
  // the gap so we don't flash the admin UI.
  if (!isAdmin) return null;

  const memberCount = space.userMemberships.length;
  const adminCount = space.userMemberships.filter(
    (m) => m.role === "admin",
  ).length;

  const filteredMembers = space.userMemberships
    .filter((m) => roleFilter === "all" || m.role === roleFilter)
    .filter((m) => {
      const q = memberQuery.trim().toLowerCase();
      if (!q) return true;
      return (
        displayName(m.user).toLowerCase().includes(q) ||
        m.user.email.toLowerCase().includes(q)
      );
    });

  const roleFilters: { key: RoleFilter; label: string }[] = [
    { key: "all", label: "All" },
    { key: "admin", label: "Admins" },
    { key: "member", label: "Members" },
  ];

  return (
    <>
      <Head>
        <title>Space settings · {space.name}</title>
      </Head>
      <main className="mx-auto max-w-6xl px-4 py-8">
        <Button asChild variant="ghost" size="sm" className="mb-4 -ml-2 gap-1.5">
          <Link href={`/spaces/${space.id}`}>
            <ArrowLeft className="h-4 w-4" aria-hidden />
            Back to {space.name}
          </Link>
        </Button>

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {/* Header */}
        <div className="flex items-start gap-4 mb-8">
          <SpaceTile
            name={space.name}
            color={resolveSpaceColor(space)}
            isPersonal={space.isPersonal}
            size="lg"
          />
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-bold truncate">{space.name}</h1>
              <Badge variant="secondary" className="shrink-0">
                admin
              </Badge>
            </div>
            <p className="text-sm text-muted-foreground mt-1">
              {memberCount} member{memberCount === 1 ? "" : "s"} ·{" "}
              {space.projectsCount} project{space.projectsCount === 1 ? "" : "s"}{" "}
              · {space.pagesCount} page{space.pagesCount === 1 ? "" : "s"} ·
              created {formatDate(space.createdAt)}
            </p>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <Button
              variant="outline"
              size="sm"
              className="gap-1.5"
              onClick={() => setEditOpen(true)}
            >
              <Pencil className="h-4 w-4" aria-hidden />
              Edit
            </Button>
            {!space.isPersonal && (
              <Button
                variant="destructive"
                size="sm"
                className="gap-1.5"
                onClick={handleDeleteSpace}
              >
                <Trash2 className="h-4 w-4" aria-hidden />
                Delete
              </Button>
            )}
          </div>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          {/* Main column */}
          <div className="lg:col-span-2 space-y-6">
            {/* Members */}
            <Card>
              <CardContent className="pt-6">
                <div className="flex items-baseline justify-between gap-2 mb-1">
                  <h2 className="text-lg font-semibold">
                    Members{" "}
                    <span className="text-muted-foreground font-normal">
                      {memberCount}
                    </span>
                  </h2>
                </div>
                <p className="text-sm text-muted-foreground mb-4">
                  People with access to this space.
                </p>

                <div className="flex flex-wrap items-center gap-2 mb-3">
                  <div className="relative flex-1 min-w-[180px]">
                    <Search
                      className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"
                      aria-hidden
                    />
                    <Input
                      value={memberQuery}
                      onChange={(e) => setMemberQuery(e.target.value)}
                      placeholder="Search members…"
                      aria-label="Search members"
                      className="pl-8"
                    />
                  </div>
                  <div className="flex items-center gap-1">
                    {roleFilters.map((f) => (
                      <Button
                        key={f.key}
                        type="button"
                        size="sm"
                        variant={roleFilter === f.key ? "default" : "outline"}
                        onClick={() => setRoleFilter(f.key)}
                      >
                        {f.label}
                      </Button>
                    ))}
                  </div>
                </div>

                <ul
                  className="divide-y divide-border rounded-md border"
                  data-testid="settings-member-list"
                >
                  {filteredMembers.map((m) => {
                    const isSelf = m.user.id === user.id;
                    return (
                      <li
                        key={m["@id"]}
                        className="flex items-center gap-3 px-3 py-2.5"
                        data-testid="settings-member"
                      >
                        <UserAvatar user={toAvatarUser(m.user)} size="sm" />
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-1.5">
                            <span className="font-medium truncate">
                              {displayName(m.user)}
                            </span>
                            {isSelf && (
                              <span className="text-xs text-muted-foreground">
                                you
                              </span>
                            )}
                          </div>
                          <p className="text-xs text-muted-foreground truncate">
                            {m.user.email}
                          </p>
                        </div>
                        {/* Role change isn't wired yet — show the role as
                            a static badge rather than a dropdown that
                            can't do anything. */}
                        <Badge
                          variant={m.role === "admin" ? "secondary" : "outline"}
                          className="shrink-0"
                        >
                          {m.role}
                        </Badge>
                      </li>
                    );
                  })}
                  {filteredMembers.length === 0 && (
                    <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                      No members match.
                    </li>
                  )}
                </ul>
              </CardContent>
            </Card>

            {/* Group memberships (read-only for now) */}
            {space.groupMemberships.length > 0 && (
              <Card>
                <CardContent className="pt-6">
                  <h2 className="text-lg font-semibold mb-1">
                    Group memberships{" "}
                    <span className="text-muted-foreground font-normal">
                      {space.groupMemberships.length}
                    </span>
                  </h2>
                  <p className="text-sm text-muted-foreground mb-4">
                    Groups attached to this space — everyone in the group gets
                    the role below.
                  </p>
                  <ul className="divide-y divide-border rounded-md border">
                    {space.groupMemberships.map((g) => (
                      <li
                        key={g["@id"]}
                        className="flex items-center gap-3 px-3 py-2.5"
                      >
                        <span className="font-medium truncate flex-1">
                          {g.userGroup.title ?? g.userGroup.id}
                        </span>
                        <Badge
                          variant={g.role === "admin" ? "secondary" : "outline"}
                          className="shrink-0"
                        >
                          {g.role}
                        </Badge>
                      </li>
                    ))}
                  </ul>
                </CardContent>
              </Card>
            )}

            {/* Invite by email */}
            <Card>
              <CardContent className="pt-6">
                <h2 className="text-lg font-semibold mb-1">Invite by email</h2>
                <p className="text-sm text-muted-foreground mb-4">
                  Send an invitation link directly to one or more email
                  addresses. Press ↵ to add, up to {MAX_INVITES} at a time.
                </p>
                <EmailChipInput
                  inputId="space-settings-invites"
                  value={inviteEmails}
                  onChange={setInviteEmails}
                  maxItems={MAX_INVITES}
                />
                {inviteMessage && (
                  <Alert
                    variant={
                      inviteMessage.kind === "error" ? "destructive" : "default"
                    }
                    className="mt-3"
                  >
                    <AlertDescription>{inviteMessage.text}</AlertDescription>
                  </Alert>
                )}
                <div className="flex justify-end mt-3">
                  <Button
                    type="button"
                    className="gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white"
                    disabled={isSendingInvites || inviteEmails.length === 0}
                    onClick={handleSendInvites}
                  >
                    <Mail className="h-4 w-4" aria-hidden />
                    {isSendingInvites ? "Sending…" : "Send invite"}
                  </Button>
                </div>
              </CardContent>
            </Card>

            {/* Pending invitations */}
            {invites.length > 0 && (
              <Card>
                <CardContent className="pt-6">
                  <h2 className="text-lg font-semibold mb-1">
                    Pending invitations{" "}
                    <span className="text-muted-foreground font-normal">
                      {invites.length}
                    </span>
                  </h2>
                  <p className="text-sm text-muted-foreground mb-4">
                    Invites that haven&apos;t been accepted yet.
                  </p>
                  <ul className="divide-y divide-border rounded-md border">
                    {invites.map((invite) => (
                      <li
                        key={invite.id}
                        className="flex items-center gap-3 px-3 py-2.5"
                      >
                        <Mail
                          className="h-4 w-4 text-muted-foreground shrink-0"
                          aria-hidden
                        />
                        <div className="min-w-0 flex-1">
                          <p className="font-medium truncate">{invite.email}</p>
                          <p className="text-xs text-muted-foreground truncate">
                            invited by {invite.invitedBy} · expires{" "}
                            {formatRelative(invite.expiresAt)}
                          </p>
                        </div>
                        <Badge
                          variant={
                            invite.role === "admin" ? "secondary" : "outline"
                          }
                          className="shrink-0"
                        >
                          {invite.role}
                        </Badge>
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
            {!space.isPersonal && (
              <Card className="border-destructive/40">
                <CardContent className="pt-6">
                  <h2 className="text-lg font-semibold text-destructive mb-1">
                    Danger zone
                  </h2>
                  <p className="text-sm text-muted-foreground mb-4">
                    Destructive actions. You can&apos;t undo these.
                  </p>

                  {/* Archive isn't built yet — show the intent but keep it
                      disabled so it can't be mistaken for working. */}
                  <div className="flex items-center justify-between gap-3 rounded-md border px-4 py-3 mb-2">
                    <div className="min-w-0">
                      <p className="font-medium flex items-center gap-1.5">
                        <Archive className="h-4 w-4" aria-hidden />
                        Archive space
                      </p>
                      <p className="text-sm text-muted-foreground">
                        Hide the space while keeping its contents. Coming soon.
                      </p>
                    </div>
                    <Button variant="outline" size="sm" disabled>
                      Archive…
                    </Button>
                  </div>

                  <div className="flex items-center justify-between gap-3 rounded-md border border-destructive/40 px-4 py-3">
                    <div className="min-w-0">
                      <p className="font-medium text-destructive">Delete space</p>
                      <p className="text-sm text-muted-foreground">
                        Permanently removes the space and everything in it. All
                        members lose access.
                      </p>
                    </div>
                    <Button
                      variant="destructive"
                      size="sm"
                      className="gap-1.5"
                      onClick={handleDeleteSpace}
                    >
                      <Trash2 className="h-4 w-4" aria-hidden />
                      Delete space…
                    </Button>
                  </div>
                </CardContent>
              </Card>
            )}
          </div>

          {/* Rail */}
          <div className="space-y-6">
            <Card>
              <CardContent className="pt-6">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-2">
                  About
                </h2>
                {space.description ? (
                  <MarkdownView
                    source={space.description}
                    className="text-sm"
                  />
                ) : (
                  <p className="text-sm text-muted-foreground">
                    No description yet.
                  </p>
                )}
                <Button
                  variant="outline"
                  size="sm"
                  className="mt-3 gap-1.5"
                  onClick={() => setEditOpen(true)}
                >
                  <Pencil className="h-3.5 w-3.5" aria-hidden />
                  Edit description
                </Button>
              </CardContent>
            </Card>

            <Card>
              <CardContent className="pt-6">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-3">
                  At a glance
                </h2>
                <dl className="space-y-2 text-sm">
                  <GlanceRow label="Kind" value={space.visibility} />
                  <GlanceRow label="Members" value={String(memberCount)} />
                  <GlanceRow label="Admins" value={String(adminCount)} />
                  <GlanceRow
                    label="Created"
                    value={formatDate(space.createdAt)}
                  />
                  <GlanceRow
                    label="Created by"
                    value={space.createdBy?.email ?? "—"}
                  />
                </dl>
              </CardContent>
            </Card>
          </div>
        </div>
      </main>

      {/* Edit dialog: name / description / color */}
      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent className="sm:max-w-lg">
          <div className="space-y-4">
            <h2 className="text-lg font-semibold">Edit space</h2>

            <div className="space-y-1.5">
              <Label htmlFor="edit-space-name">Name</Label>
              <Input
                id="edit-space-name"
                value={editName}
                onChange={(e) => setEditName(e.target.value)}
                maxLength={120}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="edit-space-description">Description</Label>
              <MarkdownEditor
                id="edit-space-description"
                ariaLabel="Description"
                value={editDescription}
                onChange={setEditDescription}
              />
            </div>

            <div className="space-y-1.5">
              <Label>Color</Label>
              <div className="flex items-center gap-3">
                <SpaceTile
                  name={editName || space.name}
                  color={editColor ?? resolveSpaceColor(space)}
                  isPersonal={space.isPersonal}
                  size="md"
                />
                <ColorSwatchPicker
                  value={editColor ?? resolveSpaceColor(space)}
                  onChange={(c) => setEditColor(c)}
                  ariaLabel="Space color"
                  disabled={isSavingMeta}
                />
              </div>
            </div>

            <Separator />

            <div className="flex justify-end gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => setEditOpen(false)}
                disabled={isSavingMeta}
              >
                Cancel
              </Button>
              <Button
                type="button"
                className="bg-emerald-600 hover:bg-emerald-500 text-white"
                disabled={isSavingMeta || !editName.trim()}
                onClick={handleSaveMeta}
              >
                {isSavingMeta ? "Saving…" : "Save changes"}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
};

const GlanceRow = ({ label, value }: { label: string; value: string }) => (
  <div className="flex items-center justify-between gap-3">
    <dt className="text-muted-foreground">{label}</dt>
    <dd className="font-medium truncate text-right">{value}</dd>
  </div>
);

export default SpaceSettings;
