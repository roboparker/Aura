import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";

/**
 * Inline list tabs for the space detail page: Projects / Discussions /
 * Pages / Tasks. Each tab independently fetches its own list filtered
 * by `?space=<iri>` (or `?project.space=` for tasks, which doesn't
 * carry its own space FK).
 *
 * Kept intentionally lean — title + a small meta row + a link out to
 * the resource's detail page. The top-level aggregators
 * (/projects, /discussions, /pages, /tasks) remain the place for
 * filter chips, search, and bulk actions.
 */

interface SpaceRef {
  "@id": string;
  id: string;
  name: string;
}

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

const listOf = <T,>(c: Collection<T>): T[] =>
  c.member ?? c["hydra:member"] ?? [];

const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });
const formatRelative = (iso: string): string => {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diffSec = Math.round((ts - Date.now()) / 1000);
  const abs = Math.abs(diffSec);
  if (abs < 60) return RELATIVE.format(diffSec, "second");
  if (abs < 3600) return RELATIVE.format(Math.round(diffSec / 60), "minute");
  if (abs < 86400) return RELATIVE.format(Math.round(diffSec / 3600), "hour");
  if (abs < 2592000)
    return RELATIVE.format(Math.round(diffSec / 86400), "day");
  if (abs < 31536000)
    return RELATIVE.format(Math.round(diffSec / 2592000), "month");
  return RELATIVE.format(Math.round(diffSec / 31536000), "year");
};

/* eslint-disable react-hooks/exhaustive-deps */
// useFetchOnce is intentionally not memo-dependent on the URL builder;
// the caller passes a stable identity via `spaceIri`.

interface FetchListOpts {
  spaceIri: string | null;
  url: (spaceIri: string) => string;
  enabled: boolean;
}

function useFetchList<T>({ spaceIri, url, enabled }: FetchListOpts) {
  const [items, setItems] = useState<T[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!spaceIri) return;
    setIsLoading(true);
    setError(null);
    try {
      const res = await fetch(url(spaceIri), {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) throw new Error("Failed to load.");
      const data: Collection<T> = await res.json();
      setItems(listOf(data));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load.");
    } finally {
      setIsLoading(false);
    }
  }, [spaceIri]);

  useEffect(() => {
    if (enabled && spaceIri) void load();
  }, [enabled, spaceIri, load]);

  return { items, isLoading, error };
}
/* eslint-enable react-hooks/exhaustive-deps */

// ---------------------------------------------------------------------
// Projects
// ---------------------------------------------------------------------

interface ProjectRow {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  owner: { id: string; email: string };
}

export const SpaceProjectsList = ({ spaceIri, enabled }: { spaceIri: string | null; enabled: boolean }) => {
  const { items, isLoading, error } = useFetchList<ProjectRow>({
    spaceIri,
    enabled,
    url: (s) =>
      `${ENTRYPOINT}/projects?space=${encodeURIComponent(s)}&itemsPerPage=100`,
  });

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }
  if (isLoading) return <p className="text-muted-foreground text-sm">Loading…</p>;
  if (items.length === 0) {
    return (
      <Card>
        <CardContent className="pt-6">
          <p className="text-muted-foreground text-sm">
            No projects in this space yet.
          </p>
        </CardContent>
      </Card>
    );
  }
  return (
    <ul className="space-y-2" data-testid="space-projects-list">
      {items.map((p) => (
        <li key={p["@id"]} data-testid="space-project-item">
          <Card>
            <CardContent className="pt-4 pb-4">
              <Link
                href={`/projects/${p.id}`}
                className="font-semibold text-foreground no-underline hover:underline"
              >
                {p.title}
              </Link>
              <p className="text-xs text-muted-foreground mt-0.5">
                {p.owner?.email} · {formatRelative(p.createdOn)}
              </p>
              {p.description && (
                <p className="text-sm text-muted-foreground mt-1 line-clamp-2">
                  {p.description}
                </p>
              )}
            </CardContent>
          </Card>
        </li>
      ))}
    </ul>
  );
};

// ---------------------------------------------------------------------
// Discussions
// ---------------------------------------------------------------------

interface DiscussionRow {
  "@id": string;
  id: string;
  title: string;
  category: string;
  isPinned: boolean;
  isLocked: boolean;
  createdAt: string;
  updatedAt: string | null;
  author: { id: string; email: string };
}

export const SpaceDiscussionsList = ({
  spaceIri,
  enabled,
}: {
  spaceIri: string | null;
  enabled: boolean;
}) => {
  const { items, isLoading, error } = useFetchList<DiscussionRow>({
    spaceIri,
    enabled,
    url: (s) =>
      `${ENTRYPOINT}/discussions?space=${encodeURIComponent(s)}&itemsPerPage=100`,
  });

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }
  if (isLoading) return <p className="text-muted-foreground text-sm">Loading…</p>;
  if (items.length === 0) {
    return (
      <Card>
        <CardContent className="pt-6">
          <p className="text-muted-foreground text-sm">
            No discussions in this space yet.
          </p>
        </CardContent>
      </Card>
    );
  }
  return (
    <ul className="space-y-2" data-testid="space-discussions-list">
      {items.map((d) => (
        <li key={d["@id"]} data-testid="space-discussion-item">
          <Card>
            <CardContent className="pt-4 pb-4">
              <div className="flex flex-wrap items-center gap-2">
                <Link
                  href={`/discussions/${d.id}`}
                  className="font-semibold text-foreground no-underline hover:underline"
                >
                  {d.title}
                </Link>
                {d.isPinned && <Badge variant="secondary">Pinned</Badge>}
                {d.isLocked && <Badge variant="secondary">Locked</Badge>}
                <Badge variant="outline" className="capitalize">
                  {d.category.replace(/-/g, " ")}
                </Badge>
              </div>
              <p className="text-xs text-muted-foreground mt-1">
                {d.author?.email} · {formatRelative(d.createdAt)}
              </p>
            </CardContent>
          </Card>
        </li>
      ))}
    </ul>
  );
};

// ---------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------

interface PageRow {
  "@id": string;
  id: string;
  title: string;
  createdAt: string;
  updatedAt: string | null;
  parent: string | null;
  createdBy: { id: string; email: string };
}

export const SpacePagesList = ({
  spaceIri,
  enabled,
}: {
  spaceIri: string | null;
  enabled: boolean;
}) => {
  const { items, isLoading, error } = useFetchList<PageRow>({
    spaceIri,
    enabled,
    url: (s) =>
      `${ENTRYPOINT}/pages?space=${encodeURIComponent(s)}&itemsPerPage=100`,
  });

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }
  if (isLoading) return <p className="text-muted-foreground text-sm">Loading…</p>;
  if (items.length === 0) {
    return (
      <Card>
        <CardContent className="pt-6">
          <p className="text-muted-foreground text-sm">
            No pages in this space yet.
          </p>
        </CardContent>
      </Card>
    );
  }
  return (
    <ul className="space-y-2" data-testid="space-pages-list">
      {items.map((p) => (
        <li key={p["@id"]} data-testid="space-page-item">
          <Card>
            <CardContent className="pt-4 pb-4">
              <div className="flex flex-wrap items-center gap-2">
                <Link
                  href={`/pages/${p.id}`}
                  className="font-semibold text-foreground no-underline hover:underline"
                >
                  {p.title}
                </Link>
                {p.parent && <Badge variant="outline">Sub-page</Badge>}
              </div>
              <p className="text-xs text-muted-foreground mt-0.5">
                {p.createdBy?.email} · {formatRelative(p.createdAt)}
                {p.updatedAt && " · edited"}
              </p>
            </CardContent>
          </Card>
        </li>
      ))}
    </ul>
  );
};

// ---------------------------------------------------------------------
// Tasks
// ---------------------------------------------------------------------

interface TaskRow {
  "@id": string;
  id: string;
  title: string;
  createdOn: string;
  completedOn: string | null;
  project: { id: string; title: string } | null;
}

export const SpaceTasksList = ({
  spaceIri,
  enabled,
}: {
  spaceIri: string | null;
  enabled: boolean;
}) => {
  // Task has no direct space FK; filter by `project.space` (added to
  // the SearchFilter property list on Task). Excludes standalone
  // personal tasks (no project) by design — those don't live in any
  // space.
  const { items, isLoading, error } = useFetchList<TaskRow>({
    spaceIri,
    enabled,
    url: (s) =>
      `${ENTRYPOINT}/tasks?project.space=${encodeURIComponent(s)}&itemsPerPage=100`,
  });

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }
  if (isLoading) return <p className="text-muted-foreground text-sm">Loading…</p>;
  if (items.length === 0) {
    return (
      <Card>
        <CardContent className="pt-6">
          <p className="text-muted-foreground text-sm">
            No tasks in this space yet.
          </p>
        </CardContent>
      </Card>
    );
  }
  return (
    <ul className="space-y-1.5" data-testid="space-tasks-list">
      {items.map((t) => (
        <li key={t["@id"]} data-testid="space-task-item">
          <Card>
            <CardContent className="pt-3 pb-3">
              <div className="flex items-center gap-2">
                <span
                  className={
                    "font-medium " +
                    (t.completedOn ? "line-through text-muted-foreground" : "")
                  }
                >
                  {t.title}
                </span>
                {t.project && (
                  <Link
                    href={`/projects/${t.project.id}`}
                    className="text-xs text-muted-foreground hover:underline"
                  >
                    in {t.project.title}
                  </Link>
                )}
              </div>
              <p className="text-xs text-muted-foreground mt-0.5">
                {formatRelative(t.createdOn)}
                {t.completedOn && " · completed"}
              </p>
            </CardContent>
          </Card>
        </li>
      ))}
    </ul>
  );
};

export type { SpaceRef };
