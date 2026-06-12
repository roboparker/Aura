import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  BookOpen,
  LayoutGrid,
  MoreHorizontal,
  Plus,
  Search,
} from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { resolveGroupColor } from "@/lib/avatarPalette";
import { formatRelative, isRecent } from "@/lib/relativeTime";
import { displayName } from "@/lib/userDisplay";
import { type Group, toGroupAvatarUser } from "@/lib/groupTypes";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import DeleteGroupDialog from "@/components/groups/DeleteGroupDialog";
import GroupTile from "@/components/groups/GroupTile";
import UserAvatar from "@/components/user/UserAvatar";

// Updates within this window get a green "edited" dot; older ones read
// as a muted "updated".
const RECENT_MS = 7 * 24 * 60 * 60 * 1000;

const ONBOARDING_STEPS: { n: string; title: string; hint: string }[] = [
  {
    n: "01",
    title: "Name it",
    hint: 'e.g. "Creative team" or "Q3 vendors"',
  },
  {
    n: "02",
    title: "Add members",
    hint: "Existing teammates or invite by email",
  },
  {
    n: "03",
    title: "Attach to a space",
    hint: "Everyone in the group joins at once",
  },
];

const Groups = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [deleteTarget, setDeleteTarget] = useState<Group | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const groupsQuery = useQuery({
    queryKey: ["groups"],
    enabled: isAuthenticated,
    queryFn: () => apiGetCollection<Group>("/groups", { errorMessage: "Failed to load groups." }),
  });
  // Stable reference so the filtered/owned useMemos below don't recompute
  // every render (react-query returns a new array identity each call).
  const groups = useMemo(() => groupsQuery.data ?? [], [groupsQuery.data]);
  const isLoading = groupsQuery.isLoading;
  const refreshGroups = () => queryClient.invalidateQueries({ queryKey: ["groups"] });
  const error =
    actionError ??
    (groupsQuery.isError
      ? groupsQuery.error instanceof Error
        ? groupsQuery.error.message
        : "Failed to load groups."
      : null);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return groups;
    return groups.filter(
      (g) =>
        g.title.toLowerCase().includes(q) ||
        g.slug.toLowerCase().includes(q),
    );
  }, [groups, search]);

  const { owned, memberOf } = useMemo(() => {
    const owned: Group[] = [];
    const memberOf: Group[] = [];
    for (const g of filtered) {
      if (user && g.owner.id === user.id) owned.push(g);
      else memberOf.push(g);
    }
    return { owned, memberOf };
  }, [filtered, user]);

  const handleLeave = async (group: Group) => {
    if (!window.confirm(`Leave "${group.title}"?`)) return;
    setActionError(null);
    try {
      await apiSend("POST", `/groups/${group.id}/leave`, {
        errorMessage: "Failed to leave group.",
      });
      void refreshGroups();
    } catch (err) {
      setActionError(err instanceof Error ? err.message : "Failed to leave group.");
    }
  };

  if (authLoading || !isAuthenticated || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Groups - Madori</title>
      </Head>

      <main className="px-6 py-8 max-w-6xl mx-auto">
        {/* Header */}
        <div className="flex flex-wrap items-end justify-between gap-4 pb-6 mb-6 border-b">
          <div className="min-w-0">
            <h1 className="flex items-center gap-2 text-3xl font-bold tracking-tight">
              <LayoutGrid className="h-6 w-6 text-emerald-600" aria-hidden />
              Groups
              {groups.length > 0 && (
                <span className="text-base font-normal text-muted-foreground">
                  {groups.length}
                </span>
              )}
            </h1>
            <p className="mt-2 text-sm text-muted-foreground">
              Reusable named sets of people you can drop into any space.
            </p>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <div className="relative w-full sm:w-64">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
              <Input
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search groups…"
                className="pl-8"
              />
            </div>
            <Button
              asChild
              size="sm"
              className="gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white"
            >
              <Link href="/groups/new">
                <Plus className="h-3.5 w-3.5" />
                New group
              </Link>
            </Button>
          </div>
        </div>

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {isLoading ? (
          <p className="text-muted-foreground">Loading groups…</p>
        ) : groups.length === 0 ? (
          <EmptyState />
        ) : filtered.length === 0 ? (
          <div className="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground">
            No groups match “{search}”.
          </div>
        ) : (
          <div className="space-y-10">
            {owned.length > 0 && (
              <GroupSection
                label="Owned by you"
                count={owned.length}
                hint="You can add or remove members and rename or delete these."
                groups={owned}
                currentUserId={user.id}
                onDelete={setDeleteTarget}
                onLeave={handleLeave}
              />
            )}
            {memberOf.length > 0 && (
              <GroupSection
                label="Member of"
                count={memberOf.length}
                hint="Groups other people own that include you."
                groups={memberOf}
                currentUserId={user.id}
                onDelete={setDeleteTarget}
                onLeave={handleLeave}
              />
            )}
          </div>
        )}

        {deleteTarget && (
          <DeleteGroupDialog
            open={!!deleteTarget}
            onOpenChange={(o) => {
              if (!o) setDeleteTarget(null);
            }}
            group={deleteTarget}
            twoFactorEnabled={user.twoFactor?.enabled ?? false}
            onDeleted={() => {
              setDeleteTarget(null);
              void refreshGroups();
            }}
          />
        )}
      </main>
    </>
  );
};

const EmptyState = () => (
  <div className="rounded-xl border bg-card px-6 py-12">
    <div className="mx-auto max-w-xl text-center">
      <div className="flex justify-center gap-3 mb-5" aria-hidden>
        {["#15803d", "#1d4ed8", "#7e22ce"].map((c) => (
          <GroupTile key={c} color={c} size="md" />
        ))}
      </div>
      <h2 className="text-lg font-semibold">No groups yet</h2>
      <p className="mt-2 text-sm text-muted-foreground">
        Groups are <span className="font-medium text-foreground">reusable
        named sets of people</span> you can drop into any space — instead of
        inviting the same five teammates by email each time.
      </p>
      <div className="mt-6 flex items-center justify-center gap-2">
        <Button
          asChild
          size="sm"
          className="gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white"
        >
          <Link href="/groups/new">
            <Plus className="h-3.5 w-3.5" />
            Create your first group
          </Link>
        </Button>
        <Button asChild size="sm" variant="outline" className="gap-1.5">
          <Link href="/guides">
            <BookOpen className="h-3.5 w-3.5" />
            How groups work
          </Link>
        </Button>
      </div>

      <div className="mt-10 grid gap-6 sm:grid-cols-3 text-left border-t pt-6">
        {ONBOARDING_STEPS.map((step) => (
          <div key={step.n}>
            <div className="font-mono text-xs text-emerald-600">{step.n}</div>
            <div className="mt-1 font-medium text-sm">{step.title}</div>
            <p className="mt-0.5 text-xs text-muted-foreground">{step.hint}</p>
          </div>
        ))}
      </div>
    </div>
  </div>
);

const GroupSection = ({
  label,
  count,
  hint,
  groups,
  currentUserId,
  onDelete,
  onLeave,
}: {
  label: string;
  count: number;
  hint: string;
  groups: Group[];
  currentUserId: string;
  onDelete: (g: Group) => void;
  onLeave: (g: Group) => void;
}) => (
  <section>
    <div className="mb-1 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
      {label}
      <span className="text-muted-foreground/70">{count}</span>
    </div>
    <p className="mb-3 text-xs text-muted-foreground">{hint}</p>
    <div className="overflow-hidden rounded-lg border">
      {/* Column header (hidden on small screens) */}
      <div className="hidden sm:grid grid-cols-[1fr_140px_180px_150px_40px] gap-3 border-b bg-muted/40 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        <span>Group</span>
        <span>Members</span>
        <span>Owner</span>
        <span>Last updated</span>
        <span className="sr-only">Actions</span>
      </div>
      <ul className="divide-y" data-testid="group-list">
        {groups.map((group) => (
          <GroupRow
            key={group["@id"]}
            group={group}
            currentUserId={currentUserId}
            onDelete={onDelete}
            onLeave={onLeave}
          />
        ))}
      </ul>
    </div>
  </section>
);

const GroupRow = ({
  group,
  currentUserId,
  onDelete,
  onLeave,
}: {
  group: Group;
  currentUserId: string;
  onDelete: (g: Group) => void;
  onLeave: (g: Group) => void;
}) => {
  const isOwner = group.owner.id === currentUserId;
  const members = group.memberships;
  const spaceCount = group.attachedSpaces.length;
  const recent = isRecent(group.updatedAt, RECENT_MS);

  return (
    <li
      className="grid grid-cols-1 sm:grid-cols-[1fr_140px_180px_150px_40px] items-center gap-3 px-4 py-3 hover:bg-accent/50 transition-colors"
      data-testid="group-item"
    >
      {/* Group */}
      <div className="flex items-center gap-3 min-w-0">
        <GroupTile color={resolveGroupColor(group)} size="sm" />
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <Link
              href={`/groups/${group.id}`}
              className="font-semibold truncate hover:underline no-underline"
            >
              {group.title}
            </Link>
            {spaceCount > 0 && (
              <Badge variant="outline" className="font-normal shrink-0">
                {spaceCount} {spaceCount === 1 ? "space" : "spaces"}
              </Badge>
            )}
          </div>
          <div className="font-mono text-xs text-muted-foreground truncate">
            g-{group.slug}
          </div>
        </div>
      </div>

      {/* Members */}
      <div className="flex items-center gap-2">
        <div className="flex -space-x-2">
          {members.slice(0, 4).map((m) => (
            <UserAvatar
              key={m["@id"]}
              user={toGroupAvatarUser(m.user)}
              size="sm"
              className="ring-2 ring-background"
            />
          ))}
        </div>
        <span className="text-sm text-muted-foreground">{members.length}</span>
      </div>

      {/* Owner */}
      <div className="flex items-center gap-2 min-w-0">
        <UserAvatar user={toGroupAvatarUser(group.owner)} size="sm" />
        <span className="text-sm truncate">{displayName(group.owner)}</span>
        {isOwner && (
          <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
            you
          </span>
        )}
      </div>

      {/* Last updated */}
      <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
        {recent && (
          <span
            className="h-1.5 w-1.5 rounded-full bg-emerald-500"
            aria-hidden
          />
        )}
        {recent ? "edited" : "updated"} {formatRelative(group.updatedAt)}
      </div>

      {/* Actions */}
      <div className="flex justify-end">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8"
              aria-label={`Actions for ${group.title}`}
            >
              <MoreHorizontal className="h-4 w-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem asChild>
              <Link href={`/groups/${group.id}`}>Open group</Link>
            </DropdownMenuItem>
            {isOwner ? (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  className="text-destructive focus:text-destructive"
                  onSelect={() => onDelete(group)}
                >
                  Delete group
                </DropdownMenuItem>
              </>
            ) : (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  className="text-destructive focus:text-destructive"
                  onSelect={() => onLeave(group)}
                >
                  Leave group
                </DropdownMenuItem>
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </li>
  );
};

export default Groups;
