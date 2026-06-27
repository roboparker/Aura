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
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { resolveGroupColor } from "@/lib/avatarPalette";
import { formatRelative, isRecent } from "@/lib/relativeTime";
import { type Group, toGroupAvatarUser } from "@/lib/groupTypes";
import { Alert, AlertDescription } from "@/components/ui/alert";
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
import PageHeader from "@/components/common/PageHeader";
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
    title: "They get access",
    hint: "Everyone in the group can see this space",
  },
];

const Groups = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();
  const spaceIri = activeSpace?.["@id"] ?? null;
  const spaceName = activeSpace?.name;

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
    queryKey: ["groups", spaceIri],
    enabled: isAuthenticated && !!spaceIri,
    queryFn: () =>
      apiGetCollection<Group>(
        `/groups?space=${encodeURIComponent(spaceIri as string)}`,
        { errorMessage: "Failed to load groups." },
      ),
  });
  // Stable reference so the filtered useMemo below doesn't recompute every
  // render (react-query returns a new array identity each call).
  const groups = useMemo(() => groupsQuery.data ?? [], [groupsQuery.data]);
  const isLoading = groupsQuery.isLoading;
  const refreshGroups = () =>
    queryClient.invalidateQueries({ queryKey: ["groups", spaceIri] });
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
        <PageHeader
          title="Groups"
          icon={<LayoutGrid className="h-6 w-6 text-emerald-600" aria-hidden />}
          count={groups.length > 0 ? groups.length : null}
          subtitle={
            spaceName
              ? `Named sets of people in “${spaceName}”. Everyone in a group gets access to the space.`
              : "Named sets of people in this space. Everyone in a group gets access to the space."
          }
          actions={
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
          }
        >
          <div className="relative w-full">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
            <Input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search groups…"
              className="pl-8"
            />
          </div>
        </PageHeader>

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
          <div className="overflow-hidden rounded-lg border" data-testid="group-list-wrap">
            {/* Column header (hidden on small screens) */}
            <div className="hidden sm:grid grid-cols-[1fr_140px_150px_40px] gap-3 border-b bg-muted/40 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              <span>Group</span>
              <span>Members</span>
              <span>Last updated</span>
              <span className="sr-only">Actions</span>
            </div>
            <ul className="divide-y" data-testid="group-list">
              {filtered.map((group) => (
                <GroupRow
                  key={group["@id"]}
                  group={group}
                  currentUserId={user.id}
                  onDelete={setDeleteTarget}
                  onLeave={handleLeave}
                />
              ))}
            </ul>
          </div>
        )}

        {deleteTarget && (
          <DeleteGroupDialog
            open={!!deleteTarget}
            onOpenChange={(o) => {
              if (!o) setDeleteTarget(null);
            }}
            group={deleteTarget}
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
        Groups are <span className="font-medium text-foreground">named sets
        of people</span> in this space — add a group and everyone in it gets
        access to the space at once, instead of inviting the same teammates
        one by one.
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
  const members = group.memberships;
  const isMember = members.some((m) => m.user.id === currentUserId);
  const recent = isRecent(group.updatedAt, RECENT_MS);

  return (
    <li
      className="grid grid-cols-1 sm:grid-cols-[1fr_140px_150px_40px] items-center gap-3 px-4 py-3 hover:bg-accent/50 transition-colors"
      data-testid="group-item"
    >
      {/* Group */}
      <div className="flex items-center gap-3 min-w-0">
        <GroupTile color={resolveGroupColor(group)} size="sm" />
        <div className="min-w-0">
          <Link
            href={`/groups/${group.id}`}
            className="font-semibold truncate hover:underline no-underline"
          >
            {group.title}
          </Link>
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
            {isMember && (
              <DropdownMenuItem onSelect={() => onLeave(group)}>
                Leave group
              </DropdownMenuItem>
            )}
            <DropdownMenuSeparator />
            <DropdownMenuItem
              className="text-destructive focus:text-destructive"
              onSelect={() => onDelete(group)}
            >
              Delete group
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </li>
  );
};

export default Groups;
