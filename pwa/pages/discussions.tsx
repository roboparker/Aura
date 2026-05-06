import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { Lock, Pin } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { displayName } from "@/lib/userDisplay";
import {
  CATEGORY_LABEL,
  formatRelative,
  type Discussion as BaseDiscussion,
  type DiscussionCategory,
} from "@/components/discussions/DiscussionsPanel";
import UserAvatar from "@/components/user/UserAvatar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";

interface ProjectRef {
  "@id": string;
  id: string;
  title: string;
}

// The top-level /discussions endpoint embeds project as `{id, title}`
// (see Project entity's `discussion:read` group), so the row can link
// out to the project and label the thread without a follow-up fetch.
type Discussion = BaseDiscussion & { project: ProjectRef };

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

const FILTERS: { value: DiscussionCategory | "all"; label: string }[] = [
  { value: "all", label: "All" },
  { value: "general", label: "General" },
  { value: "ideas", label: "Ideas" },
  { value: "announcements", label: "Announcements" },
  { value: "q-and-a", label: "Q&A" },
];

const AllDiscussionsPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [discussions, setDiscussions] = useState<Discussion[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<DiscussionCategory | "all">("all");

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      // The DiscussionAccessExtension already scopes results to the
      // current user's project memberships, so a plain GET /discussions
      // is exactly what we want here.
      const res = await fetch(`${ENTRYPOINT}/discussions?itemsPerPage=100`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) throw new Error("Failed to load discussions.");
      const data: Collection<Discussion> = await res.json();
      setDiscussions(data.member ?? data["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) void load();
  }, [isAuthenticated, load]);

  const filtered =
    filter === "all"
      ? discussions
      : discussions.filter((d) => d.category === filter);

  if (authLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Discussions - Aura</title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-4xl mx-auto px-4 py-8 space-y-6">
          <header>
            <h1 className="text-2xl font-bold">Discussions</h1>
            <p className="text-sm text-muted-foreground mt-1">
              Threads from every project you belong to.
            </p>
          </header>

          <div
            className="flex flex-wrap gap-1"
            role="tablist"
            aria-label="Filter by category"
          >
            {FILTERS.map((c) => (
              <button
                key={c.value}
                type="button"
                role="tab"
                aria-selected={filter === c.value}
                onClick={() => setFilter(c.value)}
                className={cn(
                  "px-3 py-1 rounded-full text-xs font-medium border transition-colors",
                  filter === c.value
                    ? "bg-primary text-primary-foreground border-primary"
                    : "bg-background text-muted-foreground border-input hover:text-foreground",
                )}
              >
                {c.label}
              </button>
            ))}
          </div>

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {isLoading ? (
            <p className="text-muted-foreground text-sm">Loading…</p>
          ) : filtered.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground text-sm">
                  {filter === "all"
                    ? "No discussions yet — open a project to start one."
                    : "No discussions in this category."}
                </p>
              </CardContent>
            </Card>
          ) : (
            <ul
              className="space-y-3"
              data-testid="all-discussions-list"
            >
              {filtered.map((d) => (
                <li key={d["@id"]} data-testid="discussion-item">
                  <Card>
                    <CardContent className="pt-4 pb-4">
                      <div className="flex items-start gap-3">
                        <UserAvatar user={d.author} size="sm" />
                        <div className="min-w-0 flex-1 space-y-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <Link
                              href={`/projects/${d.project.id}/discussions/${d.id}`}
                              className="font-semibold text-foreground no-underline hover:underline"
                              data-testid="discussion-title"
                            >
                              {d.title}
                            </Link>
                            {d.isPinned && (
                              <Badge variant="secondary" className="gap-1">
                                <Pin className="h-3 w-3" /> Pinned
                              </Badge>
                            )}
                            {d.isLocked && (
                              <Badge variant="secondary" className="gap-1">
                                <Lock className="h-3 w-3" /> Locked
                              </Badge>
                            )}
                            <Badge variant="outline">
                              {CATEGORY_LABEL[d.category]}
                            </Badge>
                          </div>
                          <p className="text-xs text-muted-foreground">
                            <Link
                              href={`/projects/${d.project.id}`}
                              className="hover:underline"
                            >
                              {d.project.title}
                            </Link>
                            {" · "}
                            {displayName(d.author)} ·{" "}
                            {formatRelative(d.createdAt)}
                            {d.updatedAt && " · edited"}
                          </p>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                </li>
              ))}
            </ul>
          )}
        </div>
      </main>
    </>
  );
};

export default AllDiscussionsPage;
