import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { Settings } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace, type Space } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
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
      <main className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto">
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

              {/* Space header sits OUTSIDE the tab strip — name + role
                  badges are global context. Edit-metadata moves into
                  the Overview tab below. */}
              <div className="flex items-start justify-between gap-2 mb-4">
                <h1 className="text-2xl font-bold">{space.name}</h1>
                <div className="flex items-center gap-1">
                  {space.isPersonal && <Badge variant="secondary">Private</Badge>}
                  {isAdmin && !space.isPersonal && (
                    <Badge variant="secondary">Admin</Badge>
                  )}
                  {isAdmin && (
                    <Button
                      asChild
                      variant="outline"
                      size="sm"
                      className="ml-1 gap-1.5"
                    >
                      <Link href={`/spaces/${space.id}/settings`}>
                        <Settings className="h-4 w-4" aria-hidden />
                        Settings
                      </Link>
                    </Button>
                  )}
                </div>
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

                <TabsContent value="overview" className="space-y-6 mt-0">
                  {/* Read-only overview. All management (edit metadata,
                      color, members, invites, delete) lives on the
                      admin-only Settings page reachable from the header. */}
                  {space.description && (
                    <Card>
                      <CardContent className="pt-6">
                        <p className="text-sm text-muted-foreground whitespace-pre-wrap">
                          {space.description}
                        </p>
                      </CardContent>
                    </Card>
                  )}

                  <Card>
                    <CardContent className="pt-6">
                      <h2 className="text-lg font-semibold mb-3">Members</h2>
                  <ul className="flex flex-wrap items-center gap-1 mb-3" data-testid="space-member-list">
                    {space.userMemberships.map((membership) => {
                      const isSelf = membership.user.id === user.id;
                      const label = membership.user.email;
                      return (
                        <li key={membership["@id"]} data-testid="space-member">
                          <Badge variant="secondary" className="gap-1">
                            <span>
                              {label}
                              {membership.role === "admin" && (
                                <span className="ml-1 text-xs uppercase tracking-wide opacity-70">
                                  admin
                                </span>
                              )}
                              {isSelf && <span className="ml-1 text-xs opacity-70">(you)</span>}
                            </span>
                          </Badge>
                        </li>
                      );
                    })}
                  </ul>

                  {space.groupMemberships.length > 0 && (
                    <>
                      <p className="text-xs text-muted-foreground mt-3 mb-1">
                        Groups
                      </p>
                      <ul className="flex flex-wrap gap-1 mb-3">
                        {space.groupMemberships.map((groupMembership) => (
                          <li key={groupMembership["@id"]}>
                            <Badge variant="outline">
                              {groupMembership.userGroup.title ?? groupMembership.userGroup.id}
                            </Badge>
                          </li>
                        ))}
                      </ul>
                    </>
                  )}

                </CardContent>
              </Card>
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

export default SpaceDetail;
