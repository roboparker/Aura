import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import {
  CheckSquare,
  ChevronRight,
  Clock,
  FileText,
  FolderKanban,
  MessageSquare,
  Paperclip,
  Plus,
  Settings,
  type LucideIcon,
} from "lucide-react";
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import AttachmentsPanel, {
  type Attachment,
} from "@/components/tasks/AttachmentsPanel";
import {
  SpaceDiscussionsList,
  SpacePagesList,
  SpaceProjectsList,
  SpaceTasksList,
} from "@/components/spaces/SpaceContentTabs";
import SpaceTile from "@/components/spaces/SpaceTile";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";

interface ProjectOwner {
  email: string;
  givenName?: string | null;
  familyName?: string | null;
  personalizedColor?: string;
  avatarUrls?: { thumb?: string; profile?: string } | null;
}

interface ProjectPreview {
  "@id": string;
  id: string;
  title: string;
  owner?: ProjectOwner | null;
  createdOn: string;
  taskCount: number;
  completedTaskCount: number;
}

interface ActivityActor {
  "@id": string;
  id: string;
  email: string;
  givenName?: string | null;
  familyName?: string | null;
  personalizedColor?: string;
  avatarUrls?: { thumb?: string; profile?: string } | null;
}

interface ActivityItem {
  id: number;
  action: string;
  loggedAt: string;
  objectClass: string;
  objectId: string;
  actor: string | null;
  data: Record<string, unknown>;
}

interface ActivityFeed {
  items: ActivityItem[];
  totalItems: number;
  actors: Record<string, ActivityActor>;
}

/** SpaceMember.personalizedColor is optional; UserAvatar needs a string. */
const toAvatarUser = (m: SpaceMember): AvatarUser => ({
  ...m,
  personalizedColor: m.personalizedColor ?? "#64748b",
});

const ownerToAvatar = (o: ProjectOwner | ActivityActor): AvatarUser => ({
  email: o.email,
  givenName: o.givenName,
  familyName: o.familyName,
  personalizedColor: o.personalizedColor ?? "#64748b",
  avatarUrls: o.avatarUrls,
});

const actorName = (a: ActivityActor | undefined): string => {
  if (!a) return "Someone";
  const full = `${a.givenName ?? ""} ${a.familyName ?? ""}`.trim();
  return full || a.email;
};

const formatBytes = (bytes: number): string => {
  if (bytes < 1024) return `${bytes} B`;
  const units = ["KB", "MB", "GB"];
  let value = bytes / 1024;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return `${value < 10 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`;
};

/** Short, uppercase file-type label from a filename / mime type. */
const fileLabel = (name: string, mime: string): string => {
  const ext = name.includes(".") ? name.split(".").pop() ?? "" : "";
  if (ext) return ext.slice(0, 4).toUpperCase();
  const sub = mime.split("/")[1] ?? mime;
  return sub.slice(0, 4).toUpperCase();
};

// Tab keys live in the URL (`?tab=...`) so deep links and the
// browser back-button work naturally. Unknown values fall back to
// the Overview tab.
const TABS = ["overview", "projects", "discussions", "pages", "tasks", "files"] as const;
type TabKey = (typeof TABS)[number];
const isTabKey = (v: unknown): v is TabKey =>
  typeof v === "string" && (TABS as readonly string[]).includes(v);


const SpaceDetail = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { spaces, activeSpace, setActiveSpace } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const spaceId = typeof id === "string" ? id : null;

  const [space, setSpace] = useState<Space | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Dashboard (Overview) data. Projects + pages counts ride on the
  // space object; discussions + tasks counts come from the list
  // endpoints' totalItems. `null` = still loading / unavailable.
  const [discussionsCount, setDiscussionsCount] = useState<number | null>(null);
  const [tasksCount, setTasksCount] = useState<number | null>(null);
  const [projectsPreview, setProjectsPreview] = useState<ProjectPreview[]>([]);
  const [activity, setActivity] = useState<ActivityFeed | null>(null);

  const handleAttach = async (mediaObjectIri: string) => {
    if (!space) return;
    const current = (space.attachments ?? []).map((a) => a["@id"]);
    const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
      method: "PATCH",
      credentials: "include",
      headers: { "Content-Type": "application/merge-patch+json" },
      body: JSON.stringify({ attachments: [...current, mediaObjectIri] }),
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(
        data.detail || data["hydra:description"] || "Failed to attach file.",
      );
    }
    const updated: Space = await res.json();
    setSpace(updated);
  };

  const handleDetach = async (attachment: Attachment) => {
    if (!space) return;
    const nextIris = (space.attachments ?? [])
      .filter((a) => a["@id"] !== attachment["@id"])
      .map((a) => a["@id"]);
    const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
      method: "PATCH",
      credentials: "include",
      headers: { "Content-Type": "application/merge-patch+json" },
      body: JSON.stringify({ attachments: nextIris }),
    });
    if (!res.ok) {
      throw new Error("Failed to remove attachment.");
    }
    const updated: Space = await res.json();
    setSpace(updated);
  };

  // Tab state — driven by `?tab=` so the back button works and deep
  // links land on the chosen tab. Falls back to overview for missing
  // or unknown values.
  const queryTab = router.query.tab;
  const activeTab: TabKey = isTabKey(queryTab) ? queryTab : "overview";
  const handleTabChange = (value: string) => {
    if (!isTabKey(value)) return;
    const nextTab: TabKey = value;
    // Carry forward everything except the old `tab` value so a
    // deep-link with extra search params (e.g. a future ?invite=…)
    // survives the switch.
    const nextQuery: Record<string, string | string[]> = {};
    for (const [k, v] of Object.entries(router.query)) {
      if (k === "tab" || v === undefined) continue;
      nextQuery[k] = v as string | string[];
    }
    if (nextTab !== "overview") nextQuery.tab = nextTab;
    void router.replace(
      { pathname: router.pathname, query: nextQuery },
      undefined,
      { shallow: true },
    );
  };

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const isAdmin =
    !!space &&
    !!user &&
    space.userMemberships.some(
      (m) => m.user.id === user.id && m.role === "admin",
    );

  const load = useCallback(async () => {
    if (!spaceId) return;
    setError(null);
    setIsLoading(true);
    try {
      const res = await fetch(`${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (res.status === 404) {
        setNotFound(true);
        return;
      }
      if (!res.ok) throw new Error("Failed to load space.");
      const data: Space = await res.json();
      setSpace(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load space.");
    } finally {
      setIsLoading(false);
    }
  }, [spaceId]);

  useEffect(() => {
    if (isAuthenticated && spaceId) {
      void load();
    }
  }, [isAuthenticated, spaceId, load]);

  // Viewing a space's detail page makes it the active space, so the
  // sidebar switcher tracks where you are — whether you arrived via a
  // card on /spaces, a direct link, or the switcher itself. Gated on
  // the context's space list so a space the user can't access (404)
  // never becomes active.
  useEffect(() => {
    if (!spaceId || activeSpace?.id === spaceId) return;
    const match = spaces.find((s) => s.id === spaceId);
    if (match) setActiveSpace(match);
  }, [spaceId, spaces, activeSpace?.id, setActiveSpace]);

  // Overview dashboard data: discussions/tasks counts (via totalItems)
  // and a short projects preview. Kept separate from the main space
  // load so it refreshes when the space changes without blocking the
  // header render.
  const spaceIri = space?.["@id"] ?? null;
  useEffect(() => {
    if (!spaceIri) return;
    let cancelled = false;
    const opts = {
      credentials: "include" as const,
      headers: { Accept: "application/ld+json" },
    };
    const totalOf = async (url: string): Promise<number | null> => {
      try {
        const res = await fetch(url, opts);
        if (!res.ok) return null;
        const data = await res.json();
        return data["hydra:totalItems"] ?? data.totalItems ?? 0;
      } catch {
        return null;
      }
    };
    const enc = encodeURIComponent(spaceIri);
    void (async () => {
      const [d, t] = await Promise.all([
        totalOf(`${ENTRYPOINT}/discussions?space=${enc}&itemsPerPage=1`),
        totalOf(`${ENTRYPOINT}/tasks?project.space=${enc}&itemsPerPage=1`),
      ]);
      if (cancelled) return;
      setDiscussionsCount(d);
      setTasksCount(t);
    })();
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/projects?space=${enc}&itemsPerPage=5&order[createdOn]=desc`,
          opts,
        );
        if (!res.ok) return;
        const data = await res.json();
        const list: ProjectPreview[] = data["hydra:member"] ?? data.member ?? [];
        if (!cancelled) setProjectsPreview(list);
      } catch {
        /* preview is best-effort */
      }
    })();
    void (async () => {
      const sid = spaceIri.split("/").pop() ?? "";
      try {
        const res = await fetch(
          `${ENTRYPOINT}/spaces/${encodeURIComponent(sid)}/activity?itemsPerPage=6`,
          { credentials: "include", headers: { Accept: "application/json" } },
        );
        if (!res.ok) return;
        const data: ActivityFeed = await res.json();
        if (!cancelled) setActivity(data);
      } catch {
        /* feed is best-effort */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [spaceIri]);

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
        <Card className="max-w-2xl mx-auto">
          <CardContent className="pt-6">
            <h1 className="text-xl font-bold mb-2">Space not found</h1>
            <p className="text-muted-foreground mb-4">
              It may have been deleted, or you may not be a member.
            </p>
            <Link href="/spaces" className="text-primary font-medium">
              Back to spaces
            </Link>
          </CardContent>
        </Card>
      </main>
    );
  }

  return (
    <>
      <Head>
        <title>{space ? `${space.name} - Aura` : "Space - Aura"}</title>
      </Head>
      <main className="min-h-screen bg-muted px-4 py-8">
        <div className="max-w-6xl mx-auto">
          <Link
            href="/spaces"
            className="inline-block text-sm text-primary hover:underline mb-3 no-underline"
          >
            ← All spaces
          </Link>

          {isLoading || !space ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <>
              {error && (
                <Alert variant="destructive" className="mb-4">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              {/* Dashboard header — space identity + membership glance,
                  with the admin edit entry point. */}
              <div className="flex items-start gap-4 mb-6">
                <SpaceTile
                  name={space.name}
                  color={resolveSpaceColor(space)}
                  isPersonal={space.isPersonal}
                  size="lg"
                />
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <h1 className="text-2xl font-bold truncate">{space.name}</h1>
                    <Badge variant="secondary">
                      {space.isPersonal ? "Private" : "Shared"}
                    </Badge>
                    {isAdmin && !space.isPersonal && (
                      <Badge variant="secondary">Admin</Badge>
                    )}
                  </div>
                  {space.description && (
                    <p className="text-sm text-muted-foreground mt-1 max-w-2xl line-clamp-2">
                      {space.description}
                    </p>
                  )}
                  <div className="flex items-center gap-2 mt-3">
                    <div className="flex -space-x-2">
                      {space.userMemberships.slice(0, 4).map((m) => (
                        <UserAvatar
                          key={m["@id"]}
                          user={toAvatarUser(m.user)}
                          size="sm"
                          className="ring-2 ring-background"
                        />
                      ))}
                    </div>
                    {space.userMemberships.length > 4 && (
                      <span className="inline-flex h-6 items-center rounded-full bg-muted px-2 text-xs text-muted-foreground">
                        +{space.userMemberships.length - 4}
                      </span>
                    )}
                    <span className="text-sm text-muted-foreground">
                      {space.userMemberships.length} member
                      {space.userMemberships.length === 1 ? "" : "s"}
                    </span>
                  </div>
                </div>
                {isAdmin && (
                  <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="gap-1.5 shrink-0"
                  >
                    <Link href={`/spaces/${space.id}/settings`}>
                      <Settings className="h-4 w-4" aria-hidden />
                      Edit space
                    </Link>
                  </Button>
                )}
              </div>

              {/* Stat cards — clicking jumps to the matching tab. */}
              <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <StatCard
                  icon={FolderKanban}
                  label="Projects"
                  count={space.projectsCount}
                  onClick={() => handleTabChange("projects")}
                />
                <StatCard
                  icon={FileText}
                  label="Pages"
                  count={space.pagesCount}
                  onClick={() => handleTabChange("pages")}
                />
                <StatCard
                  icon={MessageSquare}
                  label="Discussions"
                  count={discussionsCount}
                  onClick={() => handleTabChange("discussions")}
                />
                <StatCard
                  icon={CheckSquare}
                  label="Tasks"
                  count={tasksCount}
                  onClick={() => handleTabChange("tasks")}
                />
              </div>

              <Tabs value={activeTab} onValueChange={handleTabChange}>
                <TabsList className="mb-4">
                  <TabsTrigger value="overview">Overview</TabsTrigger>
                  <TabsTrigger value="projects">Projects</TabsTrigger>
                  <TabsTrigger value="discussions">Discussions</TabsTrigger>
                  <TabsTrigger value="pages">Pages</TabsTrigger>
                  <TabsTrigger value="tasks">Tasks</TabsTrigger>
                  <TabsTrigger value="files">Files</TabsTrigger>
                </TabsList>

                <TabsContent value="overview" className="mt-0">
                  <div className="grid gap-6 lg:grid-cols-3">
                    {/* Main column */}
                    <div className="lg:col-span-2 space-y-6">
                      <Card>
                        <CardContent className="pt-6">
                          <h2 className="font-semibold mb-4">Recent activity</h2>
                          {!activity || activity.items.length === 0 ? (
                            <EmptyState
                              icon={Clock}
                              title="No activity yet"
                              hint="Create a project or page and the team's activity will show up here."
                            />
                          ) : (
                            <ul className="space-y-3.5">
                              {activity.items.map((it) => {
                                const actor = it.actor
                                  ? activity.actors[it.actor]
                                  : undefined;
                                return (
                                  <li
                                    key={it.id}
                                    className="flex items-start gap-2.5"
                                  >
                                    {actor ? (
                                      <UserAvatar
                                        user={ownerToAvatar(actor)}
                                        size="sm"
                                      />
                                    ) : (
                                      <span className="h-8 w-8 rounded-full bg-muted shrink-0" />
                                    )}
                                    <p className="min-w-0 flex-1 text-sm leading-snug">
                                      <span className="font-medium">
                                        {actorName(actor)}
                                      </span>{" "}
                                      <ActivityVerb item={it} />
                                    </p>
                                    <span className="text-xs text-muted-foreground shrink-0 whitespace-nowrap">
                                      {formatRelative(it.loggedAt)}
                                    </span>
                                  </li>
                                );
                              })}
                            </ul>
                          )}
                        </CardContent>
                      </Card>

                      <Card>
                        <CardContent className="pt-6">
                          <div className="flex items-center justify-between mb-4">
                            <h2 className="font-semibold">
                              Projects{" "}
                              <span className="text-muted-foreground font-normal">
                                {space.projectsCount}
                              </span>
                            </h2>
                            <Button
                              asChild
                              variant="ghost"
                              size="sm"
                              className="gap-1 text-emerald-600 hover:text-emerald-500"
                            >
                              <Link href="/projects">
                                New project
                                <Plus className="h-3.5 w-3.5" aria-hidden />
                              </Link>
                            </Button>
                          </div>
                          {projectsPreview.length === 0 ? (
                            <EmptyState
                              icon={FolderKanban}
                              title="No projects yet"
                              hint="Projects organize tasks and timelines for a piece of the launch."
                            />
                          ) : (
                            <ul className="divide-y divide-border rounded-md border">
                              {projectsPreview.map((p) => {
                                const total = p.taskCount ?? 0;
                                const done = p.completedTaskCount ?? 0;
                                const pct =
                                  total > 0
                                    ? Math.round((done / total) * 100)
                                    : 0;
                                return (
                                  <li key={p["@id"]}>
                                    <Link
                                      href={`/projects/${p.id}`}
                                      className="flex items-center gap-3 px-3 py-3 hover:bg-accent"
                                    >
                                      <div className="min-w-0 flex-1">
                                        <p className="font-medium truncate">
                                          {p.title}
                                        </p>
                                        <div className="flex items-center gap-2 mt-1.5">
                                          <span className="h-1.5 flex-1 max-w-[180px] rounded-full bg-muted overflow-hidden">
                                            <span
                                              className="block h-full rounded-full bg-emerald-500"
                                              style={{ width: `${pct}%` }}
                                            />
                                          </span>
                                          <span className="text-xs text-muted-foreground shrink-0">
                                            {done}/{total} tasks
                                          </span>
                                        </div>
                                      </div>
                                      {p.owner && (
                                        <UserAvatar
                                          user={ownerToAvatar(p.owner)}
                                          size="sm"
                                        />
                                      )}
                                      <ChevronRight
                                        className="h-4 w-4 text-muted-foreground/60 shrink-0"
                                        aria-hidden
                                      />
                                    </Link>
                                  </li>
                                );
                              })}
                            </ul>
                          )}
                        </CardContent>
                      </Card>
                    </div>

                    {/* Rail */}
                    <div className="space-y-6">
                      <Card>
                        <CardContent className="pt-6">
                          <div className="flex items-center justify-between mb-3">
                            <h2 className="font-semibold">
                              Members{" "}
                              <span className="text-muted-foreground font-normal">
                                {space.userMemberships.length}
                              </span>
                            </h2>
                            {isAdmin && (
                              <Link
                                href={`/spaces/${space.id}/settings`}
                                className="text-sm text-emerald-600 hover:underline"
                              >
                                Manage
                              </Link>
                            )}
                          </div>
                          <ul
                            className="space-y-2.5"
                            data-testid="space-member-list"
                          >
                            {space.userMemberships.map((m) => {
                              const isSelf = m.user.id === user.id;
                              return (
                                <li
                                  key={m["@id"]}
                                  className="flex items-center gap-2"
                                  data-testid="space-member"
                                >
                                  <UserAvatar
                                    user={toAvatarUser(m.user)}
                                    size="sm"
                                  />
                                  <span className="text-sm font-medium truncate flex-1">
                                    {displayName(m.user)}
                                    {isSelf && (
                                      <span className="ml-1 text-xs text-muted-foreground">
                                        you
                                      </span>
                                    )}
                                  </span>
                                  <Badge
                                    variant={
                                      m.role === "admin" ? "secondary" : "outline"
                                    }
                                    className="shrink-0"
                                  >
                                    {m.role}
                                  </Badge>
                                </li>
                              );
                            })}
                          </ul>
                        </CardContent>
                      </Card>

                      <Card>
                        <CardContent className="pt-6">
                          <div className="flex items-center justify-between mb-3">
                            <h2 className="font-semibold">
                              Shared files{" "}
                              <span className="text-muted-foreground font-normal">
                                {(space.attachments ?? []).length}
                              </span>
                            </h2>
                            {(space.attachments ?? []).length > 0 && (
                              <button
                                type="button"
                                onClick={() => handleTabChange("files")}
                                className="text-sm text-emerald-600 hover:underline"
                              >
                                All files
                              </button>
                            )}
                          </div>
                          {(space.attachments ?? []).length === 0 ? (
                            <EmptyState
                              icon={Paperclip}
                              title="No files yet"
                              hint="Drag files onto any task or page to share them with the space."
                            />
                          ) : (
                            <ul className="space-y-2.5">
                              {(space.attachments ?? []).slice(0, 4).map((a) => (
                                <li
                                  key={a["@id"]}
                                  className="flex items-center gap-2.5"
                                >
                                  <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted text-[10px] font-semibold text-muted-foreground">
                                    {fileLabel(a.originalName, a.mimeType)}
                                  </span>
                                  <div className="min-w-0 flex-1">
                                    <p className="text-sm truncate">
                                      {a.originalName}
                                    </p>
                                    <p className="text-xs text-muted-foreground truncate">
                                      {formatBytes(a.byteSize)}
                                      {a.owner
                                        ? ` · ${a.owner.givenName ?? a.owner.email}`
                                        : ""}
                                      {a.createdOn
                                        ? ` · ${formatRelative(a.createdOn)}`
                                        : ""}
                                    </p>
                                  </div>
                                </li>
                              ))}
                            </ul>
                          )}
                        </CardContent>
                      </Card>

                      <Card>
                        <CardContent className="pt-6">
                          <h2 className="font-semibold mb-3">Quick actions</h2>
                          <div className="space-y-2">
                            <QuickAction
                              icon={FolderKanban}
                              label="New project"
                              href="/projects"
                            />
                            <QuickAction
                              icon={FileText}
                              label="New page"
                              href="/pages"
                            />
                            <QuickAction
                              icon={MessageSquare}
                              label="New discussion"
                              href="/discussions"
                            />
                          </div>
                        </CardContent>
                      </Card>
                    </div>
                  </div>
                </TabsContent>

                <TabsContent value="projects" className="mt-0">
                  <SpaceProjectsList
                    spaceIri={space["@id"]}
                    enabled={activeTab === "projects"}
                  />
                </TabsContent>

                <TabsContent value="discussions" className="mt-0">
                  <SpaceDiscussionsList
                    spaceIri={space["@id"]}
                    enabled={activeTab === "discussions"}
                  />
                </TabsContent>

                <TabsContent value="pages" className="mt-0">
                  <SpacePagesList
                    spaceIri={space["@id"]}
                    enabled={activeTab === "pages"}
                  />
                </TabsContent>

                <TabsContent value="tasks" className="mt-0">
                  <SpaceTasksList
                    spaceIri={space["@id"]}
                    enabled={activeTab === "tasks"}
                  />
                </TabsContent>

                <TabsContent value="files" className="mt-0">
                  <Card data-testid="space-attachments">
                    <CardContent className="pt-6">
                      <AttachmentsPanel
                        taskTitle={space.name}
                        attachments={space.attachments ?? []}
                        // Only admins can edit the space; non-admins
                        // hit the same `Patch` security expression
                        // that powers metadata edits.
                        canDeleteAll={isAdmin}
                        onAttach={handleAttach}
                        onDetach={handleDetach}
                      />
                    </CardContent>
                  </Card>
                </TabsContent>
              </Tabs>
            </>
          )}
        </div>
      </main>
    </>
  );
};

const StatCard = ({
  icon: Icon,
  label,
  count,
  onClick,
}: {
  icon: LucideIcon;
  label: string;
  count: number | null;
  onClick: () => void;
}) => (
  <button
    type="button"
    onClick={onClick}
    className="group flex items-center gap-3 rounded-lg border bg-card p-4 text-left transition-colors hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring"
  >
    <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
      <Icon className="h-4 w-4" aria-hidden />
    </span>
    <span className="min-w-0 flex-1">
      <span className="block text-lg font-semibold leading-tight">
        {count ?? "—"}
      </span>
      <span className="block text-xs text-muted-foreground">{label}</span>
    </span>
    <ChevronRight
      className="h-4 w-4 text-muted-foreground/60 group-hover:text-muted-foreground shrink-0"
      aria-hidden
    />
  </button>
);

const EmptyState = ({
  icon: Icon,
  title,
  hint,
}: {
  icon: LucideIcon;
  title: string;
  hint: string;
}) => (
  <div className="flex flex-col items-center justify-center rounded-md border border-dashed py-10 px-4 text-center">
    <span className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground mb-3">
      <Icon className="h-5 w-5" aria-hidden />
    </span>
    <p className="font-medium text-sm">{title}</p>
    <p className="text-xs text-muted-foreground mt-1 max-w-xs">{hint}</p>
  </div>
);

/** Renders the verb phrase for an activity row, e.g. "created the project Foo". */
const ActivityVerb = ({ item }: { item: ActivityItem }) => {
  const type = item.objectClass.toLowerCase();
  const noun = type === "project" ? "project" : type === "page" ? "page" : "task";
  const rawTitle = item.data?.title;
  const title =
    typeof rawTitle === "string" && rawTitle.trim() ? rawTitle.trim() : null;
  const titleNode = title ? (
    <span className="font-medium">{title}</span>
  ) : null;

  if (item.action === "create") {
    return (
      <>
        created the {noun}
        {titleNode ? <> {titleNode}</> : ""}
      </>
    );
  }
  if (item.action === "remove") {
    return (
      <>
        deleted a {noun}
        {titleNode ? <> {titleNode}</> : ""}
      </>
    );
  }
  return titleNode ? <>edited {titleNode}</> : <>updated a {noun}</>;
};

const QuickAction = ({
  icon: Icon,
  label,
  href,
}: {
  icon: LucideIcon;
  label: string;
  href: string;
}) => (
  <Button
    asChild
    variant="outline"
    className="w-full justify-start gap-2 font-normal"
  >
    <Link href={href}>
      <Icon className="h-4 w-4" aria-hidden />
      {label}
    </Link>
  </Button>
);

export default SpaceDetail;
