import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { Check, ChevronDown, Mail, Plus, UserPlus, Users, X } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import {
  useActiveSpace,
  type Space,
  type SpaceMember,
} from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { formatRelative } from "@/lib/relativeTime";
import { displayName } from "@/lib/userDisplay";
import { resolveGroupColor } from "@/lib/avatarPalette";
import { type Group } from "@/lib/groupTypes";
import {
  type SpaceRole,
  type SpaceRoleCollection,
  type SpaceRoleRef,
} from "@/lib/roleTypes";
import { cn } from "@/lib/utils";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import PageHeader from "@/components/common/PageHeader";
import GroupTile from "@/components/groups/GroupTile";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";

type Role = "admin" | "member";

interface PendingInvite {
  id: string;
  email: string;
  invitedBy: string;
  role: string;
  createdAt: string;
  expiresAt: string;
}

interface GroupCollection {
  member?: Group[];
  "hydra:member"?: Group[];
}

const toAvatarUser = (m: SpaceMember): AvatarUser => ({
  ...m,
  personalizedColor: m.personalizedColor ?? "#64748b",
});

/**
 * Inline editor for a member's internal cost rate (#653/#666) — currency
 * units in the box, minor units on the wire. Blank clears the rate. Commits
 * on blur/Enter so tabbing down the roster is fast.
 */
const CostRateInput = ({
  value,
  onCommit,
}: {
  value: number | null;
  onCommit: (minor: number | null) => void;
}) => {
  const [draft, setDraft] = useState(value === null ? "" : (value / 100).toFixed(2));
  useEffect(() => {
    setDraft(value === null ? "" : (value / 100).toFixed(2));
  }, [value]);

  const commit = () => {
    const trimmed = draft.trim();
    if (trimmed === "") {
      if (value !== null) onCommit(null);
      return;
    }
    const parsed = parseFloat(trimmed);
    if (!Number.isFinite(parsed) || parsed < 0) {
      setDraft(value === null ? "" : (value / 100).toFixed(2));
      return;
    }
    const minor = Math.round(parsed * 100);
    if (minor !== value) onCommit(minor);
  };

  return (
    <div
      className="flex items-center gap-1"
      title="Internal cost per hour — feeds the profitability report"
    >
      <Input
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === "Enter") e.currentTarget.blur();
        }}
        inputMode="decimal"
        placeholder="cost/h"
        aria-label="Cost rate per hour"
        className="h-8 w-20 text-right text-sm tabular-nums"
      />
    </div>
  );
};

/** Compact role picker (Admin / Member) — used per member row and on invite. */
const RoleSelect = ({
  value,
  onChange,
  disabled,
}: {
  value: Role;
  onChange: (role: Role) => void;
  disabled?: boolean;
}) => (
  <DropdownMenu>
    <DropdownMenuTrigger asChild>
      <button
        type="button"
        disabled={disabled}
        className="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium capitalize hover:bg-accent disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-ring"
      >
        <span
          className={cn(
            "h-1.5 w-1.5 rounded-full",
            value === "admin" ? "bg-violet-500" : "bg-emerald-500",
          )}
        />
        {value}
        <ChevronDown className="h-3 w-3 text-muted-foreground" aria-hidden />
      </button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" className="min-w-[120px]">
      <DropdownMenuItem onSelect={() => onChange("admin")}>Admin</DropdownMenuItem>
      <DropdownMenuItem onSelect={() => onChange("member")}>Member</DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
);

/**
 * Per-member custom-role assignment (#space-roles): a checkbox dropdown over
 * the space's roles. No roles = full access. Disabled for admins (they always
 * have everything, so roles don't apply).
 */
const MemberRoles = ({
  allRoles,
  assigned,
  disabled,
  onChange,
}: {
  allRoles: SpaceRole[];
  assigned: SpaceRoleRef[];
  disabled?: boolean;
  onChange: (iris: string[]) => void;
}) => {
  const assignedIds = new Set(assigned.map((r) => r.id));
  const toggle = (role: SpaceRole) => {
    const next = assignedIds.has(role.id)
      ? assigned.filter((r) => r.id !== role.id).map((r) => r["@id"])
      : [...assigned.map((r) => r["@id"]), role["@id"]];
    onChange(next);
  };
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          disabled={disabled}
          className="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium hover:bg-accent disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-ring"
        >
          {disabled
            ? "Full access"
            : assigned.length === 0
              ? "Member (default)"
              : `${assigned.length} role${assigned.length === 1 ? "" : "s"}`}
          <ChevronDown className="h-3 w-3 text-muted-foreground" aria-hidden />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="min-w-[200px]">
        {/* The built-in Member role is the implicit default — not assigned. */}
        {allRoles
          .filter((role) => role.builtinKey !== "member")
          .map((role) => (
          <DropdownMenuItem
            key={role["@id"]}
            onSelect={(e) => {
              e.preventDefault();
              toggle(role);
            }}
            className="gap-2"
          >
            <span
              className="h-2.5 w-2.5 shrink-0 rounded-full"
              style={{ backgroundColor: role.color ?? "#6b7280" }}
              aria-hidden
            />
            <span className="min-w-0 flex-1 truncate">{role.name}</span>
            {assignedIds.has(role.id) && (
              <Check className="h-3.5 w-3.5 shrink-0" aria-hidden />
            )}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
};

/**
 * Admin-only "people" surface for a space (`/spaces/{id}/users`): direct
 * members, pending invites, and the space's groups — moved out of the general
 * settings page so member/invite/group management lives in one place.
 */
const SpaceUsers = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { refresh } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const spaceId = typeof id === "string" ? id : null;

  const [space, setSpace] = useState<Space | null>(null);
  const [invites, setInvites] = useState<PendingInvite[]>([]);
  const [groups, setGroups] = useState<Group[]>([]);
  const [roles, setRoles] = useState<SpaceRole[]>([]);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteRole, setInviteRole] = useState<Role>("member");
  const [isInviting, setIsInviting] = useState(false);
  const [inviteMessage, setInviteMessage] = useState<{
    text: string;
    kind: "success" | "error";
  } | null>(null);

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

      const groupsRes = await fetch(
        `${ENTRYPOINT}/groups?space=${encodeURIComponent(data["@id"])}`,
        { credentials: "include", headers: { Accept: "application/ld+json" } },
      );
      if (groupsRes.ok) {
        const groupData: GroupCollection = await groupsRes.json();
        setGroups(groupData.member ?? groupData["hydra:member"] ?? []);
      }

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

  const handleChangeRole = async (membership: { id: string }, role: Role) => {
    if (!space) return;
    setError(null);
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/members/${encodeURIComponent(membership.id)}`,
      {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ role }),
      },
    );
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      setError(data.error || "Failed to change role.");
      return;
    }
    await load();
    await refresh();
  };

  const handleSetMemberRoles = async (
    membership: { id: string },
    iris: string[],
  ) => {
    if (!space) return;
    setError(null);
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/members/${encodeURIComponent(membership.id)}`,
      {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ roles: iris }),
      },
    );
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      setError(data.error || "Failed to update roles.");
      return;
    }
    await load();
    await refresh();
  };

  const handleSetCostRate = async (
    membership: { id: string },
    costRateAmount: number | null,
  ) => {
    if (!space) return;
    setError(null);
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/members/${encodeURIComponent(membership.id)}`,
      {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ costRateAmount }),
      },
    );
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      setError(data.error || "Failed to update the cost rate.");
      return;
    }
    await load();
  };

  const handleRemoveMember = async (membership: { id: string }, label: string) => {
    if (!space) return;
    if (!window.confirm(`Remove ${label} from this space?`)) return;
    setError(null);
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/members/${encodeURIComponent(membership.id)}`,
      { method: "DELETE", credentials: "include" },
    );
    if (!res.ok && res.status !== 204) {
      const data = await res.json().catch(() => ({}));
      setError(data.error || "Failed to remove member.");
      return;
    }
    await load();
    await refresh();
  };

  const handleInvite = async () => {
    if (!space || !inviteEmail.trim()) return;
    setIsInviting(true);
    setInviteMessage(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/members`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ email: inviteEmail.trim(), role: inviteRole }),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || "Failed to invite.");
      setInviteMessage({
        text:
          data.status === "added"
            ? `${data.email} is now a member.`
            : `Invitation sent to ${data.email}.`,
        kind: "success",
      });
      setInviteEmail("");
      await load();
      await refresh();
    } catch (err) {
      setInviteMessage({
        text: err instanceof Error ? err.message : "Failed to invite.",
        kind: "error",
      });
    } finally {
      setIsInviting(false);
    }
  };

  const handleRevokeInvite = async (invite: PendingInvite) => {
    if (!space) return;
    if (!window.confirm(`Revoke the invitation for ${invite.email}?`)) return;
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
          <p className="text-muted-foreground mb-4">
            It may have been deleted, or you no longer have access.
          </p>
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

  if (!isAdmin) return null;

  return (
    <>
      <Head>
        <title>Users · {space.name}</title>
      </Head>
      <div className="mx-auto max-w-5xl px-4 py-8">
        <PageHeader
          title="Users"
          icon={<Users className="h-6 w-6 text-cyan-600 dark:text-cyan-400" />}
          subtitle={`Members, invites, and groups for “${space.name}”.`}
        />

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {/* Member + invite management only applies to shared spaces. */}
        {space.visibility === "shared" && (
          <>
            {/* Members */}
            <Card className="mb-6">
              <CardContent className="pt-6 space-y-4">
                <h2 className="font-semibold">
                  Members{" "}
                  <span className="text-muted-foreground font-normal">
                    {space.userMemberships.length}
                  </span>
                </h2>

                <form
                  className="flex flex-wrap items-center gap-2"
                  onSubmit={(e) => {
                    e.preventDefault();
                    void handleInvite();
                  }}
                >
                  <div className="relative flex-1 min-w-[180px]">
                    <Mail
                      className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"
                      aria-hidden
                    />
                    <Input
                      type="email"
                      value={inviteEmail}
                      onChange={(e) => setInviteEmail(e.target.value)}
                      placeholder="Invite by email…"
                      aria-label="Invite by email"
                      className="pl-8"
                    />
                  </div>
                  <RoleSelect value={inviteRole} onChange={setInviteRole} />
                  <Button
                    type="submit"
                    size="sm"
                    className="gap-1.5"
                    disabled={isInviting || !inviteEmail.trim()}
                  >
                    <UserPlus className="h-4 w-4" aria-hidden />
                    {isInviting ? "Adding…" : "Add"}
                  </Button>
                </form>
                {inviteMessage && (
                  <p
                    role="alert"
                    className={cn(
                      "text-sm",
                      inviteMessage.kind === "success"
                        ? "text-muted-foreground"
                        : "text-destructive",
                    )}
                  >
                    {inviteMessage.text}
                  </p>
                )}

                <ul className="divide-y divide-border rounded-md border">
                  {space.userMemberships.map((m) => {
                    const isSelf = m.user.id === user.id;
                    const label = displayName(m.user);
                    return (
                      <li
                        key={m["@id"]}
                        className="flex items-center gap-3 px-3 py-2.5"
                      >
                        <UserAvatar user={toAvatarUser(m.user)} size="sm" />
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center gap-1.5">
                            <span className="font-medium truncate">{label}</span>
                            {isSelf && (
                              <span className="text-xs text-muted-foreground">you</span>
                            )}
                          </div>
                          <p className="text-xs text-muted-foreground truncate">
                            {m.user.email}
                          </p>
                        </div>
                        <CostRateInput
                          value={m.costRateAmount ?? null}
                          onCommit={(minor) => void handleSetCostRate(m, minor)}
                        />
                        <RoleSelect
                          value={m.role}
                          onChange={(role) => void handleChangeRole(m, role)}
                        />
                        {roles.length > 0 && (
                          <MemberRoles
                            allRoles={roles}
                            assigned={m.roles ?? []}
                            disabled={m.role === "admin"}
                            onChange={(iris) => void handleSetMemberRoles(m, iris)}
                          />
                        )}
                        <button
                          type="button"
                          onClick={() => void handleRemoveMember(m, label)}
                          aria-label={`Remove ${label}`}
                          className="text-muted-foreground hover:text-destructive p-1"
                        >
                          <X className="h-4 w-4" aria-hidden />
                        </button>
                      </li>
                    );
                  })}
                </ul>
              </CardContent>
            </Card>

            {/* Pending invites */}
            <Card className="mb-6">
              <CardContent className="pt-6 space-y-3">
                <h2 className="font-semibold">
                  Pending invites{" "}
                  <span className="text-muted-foreground font-normal">
                    {invites.length}
                  </span>
                </h2>
                {invites.length === 0 ? (
                  <div className="rounded-md border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                    No pending invitations — invited teammates will appear here
                    until they accept.
                  </div>
                ) : (
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
                          variant={invite.role === "admin" ? "secondary" : "outline"}
                          className="shrink-0 capitalize"
                        >
                          {invite.role}
                        </Badge>
                        <Badge variant="outline" className="shrink-0">
                          Sent
                        </Badge>
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="text-destructive hover:text-destructive"
                          onClick={() => void handleRevokeInvite(invite)}
                        >
                          Revoke
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </>
        )}

        {/* Groups */}
        <Card className="mb-6">
          <CardContent className="pt-6 space-y-3">
            <div className="flex items-center justify-between gap-2">
              <h2 className="font-semibold">
                Groups{" "}
                <span className="text-muted-foreground font-normal">
                  {groups.length}
                </span>
              </h2>
              <Button
                asChild
                size="sm"
                variant="outline"
                className="gap-1.5"
              >
                <Link href="/groups/new">
                  <Plus className="h-3.5 w-3.5" aria-hidden />
                  New group
                </Link>
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">
              Named sets of people in this space — everyone in a group gets
              access to the space.
            </p>
            {groups.length === 0 ? (
              <div className="rounded-md border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                No groups yet.
              </div>
            ) : (
              <ul className="divide-y divide-border rounded-md border">
                {groups.map((group) => (
                  <li key={group["@id"]}>
                    <Link
                      href={`/groups/${group.id}`}
                      className="flex items-center gap-3 px-3 py-2.5 no-underline hover:bg-accent/50"
                    >
                      <GroupTile color={resolveGroupColor(group)} size="sm" />
                      <div className="min-w-0 flex-1">
                        <p className="font-medium truncate">{group.title}</p>
                        <p className="text-xs text-muted-foreground">
                          {group.memberships.length}{" "}
                          {group.memberships.length === 1 ? "member" : "members"}
                        </p>
                      </div>
                      <span className="text-xs text-muted-foreground">Manage</span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </>
  );
};

export default SpaceUsers;
