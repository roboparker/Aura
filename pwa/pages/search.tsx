import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Search as SearchIcon,
  X,
} from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { highlightMatches } from "@/lib/highlightMatches";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

interface ProjectRef {
  "@id": string;
  id: string;
  name: string;
}

interface TagRef {
  "@id": string;
  id: string;
  title: string;
  color: string;
}

interface AssigneeRef {
  "@id": string;
  id: string;
  email: string;
  givenName?: string | null;
  familyName?: string | null;
  nickname?: string | null;
}

interface TaskHit {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  completedOn: string | null;
  dueDate: string | null;
  project: string | null;
  tags?: TagRef[];
}

interface ProjectHit {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
}

interface DiscussionAuthor {
  "@id": string;
  email?: string | null;
  givenName?: string | null;
  familyName?: string | null;
  nickname?: string | null;
}

interface DiscussionHit {
  "@id": string;
  id: string;
  title: string;
  body: string;
  category: string;
  isPinned: boolean;
  isLocked: boolean;
  createdAt: string;
  author: DiscussionAuthor;
}

type Status = "" | "open" | "completed";
type SortKey =
  | "relevance"
  | "createdOn:desc"
  | "createdOn:asc"
  | "dueDate:asc"
  | "dueDate:desc"
  | "title:asc";
type Kind = "tasks" | "projects" | "discussions";

interface FilterState {
  q: string;
  kind: Kind;
  status: Status;
  dueFrom: string; // yyyy-mm-dd
  dueTo: string;
  project: string; // IRI
  assignee: string; // IRI
  tags: string[]; // IRIs
  sort: SortKey;
  page: number;
}

const PAGE_SIZE = 20;

const SORT_LABELS: Record<SortKey, string> = {
  relevance: "Relevance",
  "createdOn:desc": "Recently created",
  "createdOn:asc": "Oldest first",
  "dueDate:asc": "Due soonest",
  "dueDate:desc": "Due latest",
  "title:asc": "Title A→Z",
};

const SearchPage = () => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const filters = useMemo<FilterState>(() => readFilters(router.query), [router.query]);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  const replaceFilters = useCallback(
    (patch: Partial<FilterState>) => {
      const next = { ...filters, ...patch };
      // Reset to page 1 whenever the query or any filter changes — the
      // caller's previous page index is meaningless against a new
      // result set.
      if (
        patch.q !== undefined ||
        patch.kind !== undefined ||
        patch.status !== undefined ||
        patch.dueFrom !== undefined ||
        patch.dueTo !== undefined ||
        patch.project !== undefined ||
        patch.assignee !== undefined ||
        patch.tags !== undefined ||
        patch.sort !== undefined
      ) {
        next.page = 1;
      }
      void router.replace({ pathname: "/search", query: writeFilters(next) }, undefined, {
        shallow: false,
      });
    },
    [filters, router],
  );

  if (isLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>{filters.q ? `Search: ${filters.q}` : "Search"} - Aura</title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-5xl mx-auto px-4 py-8 space-y-6">
          <SearchHeader filters={filters} onChange={replaceFilters} />
          <KindTabs
            value={filters.kind}
            onChange={(kind) => replaceFilters({ kind })}
          />
          {filters.kind === "tasks" && (
            <FilterChipsBar filters={filters} onChange={replaceFilters} />
          )}
          {filters.kind === "tasks" && (
            <TaskResults
              filters={filters}
              onPageChange={(page) => replaceFilters({ page })}
            />
          )}
          {filters.kind === "projects" && (
            <ProjectResults
              filters={filters}
              onPageChange={(page) => replaceFilters({ page })}
            />
          )}
          {filters.kind === "discussions" && (
            <DiscussionResults
              filters={filters}
              onPageChange={(page) => replaceFilters({ page })}
            />
          )}
        </div>
      </main>
    </>
  );
};

interface SearchHeaderProps {
  filters: FilterState;
  onChange: (patch: Partial<FilterState>) => void;
}

const SearchHeader = ({ filters, onChange }: SearchHeaderProps) => {
  const [draft, setDraft] = useState(filters.q);
  useEffect(() => setDraft(filters.q), [filters.q]);

  return (
    <header className="space-y-3">
      <h1 className="text-2xl font-bold">Search</h1>
      <form
        role="search"
        onSubmit={(e) => {
          e.preventDefault();
          onChange({ q: draft.trim() });
        }}
        className="relative"
      >
        <SearchIcon
          className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"
          aria-hidden
        />
        <Input
          type="search"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          placeholder="Search by keyword…"
          className="pl-9"
          autoFocus
          data-testid="search-page-input"
        />
      </form>
    </header>
  );
};

interface FilterChipsBarProps {
  filters: FilterState;
  onChange: (patch: Partial<FilterState>) => void;
}

const FilterChipsBar = ({ filters, onChange }: FilterChipsBarProps) => {
  const [projects, setProjects] = useState<ProjectRef[]>([]);
  const [tags, setTags] = useState<TagRef[]>([]);
  const [assignees, setAssignees] = useState<AssigneeRef[]>([]);

  // Fetch the filter dropdown sources once. All three endpoints already
  // scope to the current user (assignable-users = self + project members
  // across every project I belong to), so we don't need any further
  // filtering.
  useEffect(() => {
    let cancelled = false;
    void Promise.all([
      fetch(`${ENTRYPOINT}/projects?itemsPerPage=100`, { credentials: "include" }),
      fetch(`${ENTRYPOINT}/tags?itemsPerPage=100`, { credentials: "include" }),
      fetch(`${ENTRYPOINT}/me/assignable-users`, { credentials: "include" }),
    ]).then(async ([projectsRes, tagsRes, assigneesRes]) => {
      if (cancelled) return;
      if (projectsRes.ok) {
        const data = await projectsRes.json();
        setProjects(data["hydra:member"] ?? data.member ?? []);
      }
      if (tagsRes.ok) {
        const data = await tagsRes.json();
        setTags(data["hydra:member"] ?? data.member ?? []);
      }
      if (assigneesRes.ok) {
        const data = await assigneesRes.json();
        setAssignees(data["hydra:member"] ?? data.member ?? []);
      }
    });
    return () => {
      cancelled = true;
    };
  }, []);

  const toggleTag = (iri: string) => {
    const next = filters.tags.includes(iri)
      ? filters.tags.filter((t) => t !== iri)
      : [...filters.tags, iri];
    onChange({ tags: next });
  };

  const activeChips: { key: string; label: string; clear: () => void }[] = [];
  if (filters.status) {
    activeChips.push({
      key: "status",
      label: `Status: ${filters.status}`,
      clear: () => onChange({ status: "" }),
    });
  }
  if (filters.dueFrom) {
    activeChips.push({
      key: "dueFrom",
      label: `Due ≥ ${filters.dueFrom}`,
      clear: () => onChange({ dueFrom: "" }),
    });
  }
  if (filters.dueTo) {
    activeChips.push({
      key: "dueTo",
      label: `Due ≤ ${filters.dueTo}`,
      clear: () => onChange({ dueTo: "" }),
    });
  }
  if (filters.project) {
    const project = projects.find((p) => p["@id"] === filters.project);
    activeChips.push({
      key: "project",
      label: `Project: ${project?.name ?? "…"}`,
      clear: () => onChange({ project: "" }),
    });
  }
  if (filters.assignee) {
    const assignee = assignees.find((a) => a["@id"] === filters.assignee);
    activeChips.push({
      key: "assignee",
      label: `Assignee: ${assignee ? assigneeLabel(assignee) : "…"}`,
      clear: () => onChange({ assignee: "" }),
    });
  }
  filters.tags.forEach((iri) => {
    const tag = tags.find((t) => t["@id"] === iri);
    activeChips.push({
      key: `tag-${iri}`,
      label: `Tag: ${tag?.title ?? "…"}`,
      clear: () => toggleTag(iri),
    });
  });

  return (
    <div
      className="rounded-md border bg-background p-3 space-y-3"
      data-testid="search-filter-bar"
    >
      <div className="flex flex-wrap items-center gap-3">
        <SegmentedStatus
          value={filters.status}
          onChange={(status) => onChange({ status })}
        />
        <DueDateRange
          from={filters.dueFrom}
          to={filters.dueTo}
          onChange={(dueFrom, dueTo) => onChange({ dueFrom, dueTo })}
        />
        <ProjectDropdown
          value={filters.project}
          options={projects}
          onChange={(project) => onChange({ project })}
        />
        <AssigneeDropdown
          value={filters.assignee}
          options={assignees}
          onChange={(assignee) => onChange({ assignee })}
        />
        <SortDropdown value={filters.sort} onChange={(sort) => onChange({ sort })} />
      </div>

      {tags.length > 0 && (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs text-muted-foreground">Tags:</span>
          {tags.map((tag) => {
            const active = filters.tags.includes(tag["@id"]);
            return (
              <button
                key={tag["@id"]}
                type="button"
                onClick={() => toggleTag(tag["@id"])}
                className={cn(
                  "rounded-full border px-2.5 py-0.5 text-xs transition-colors",
                  active
                    ? "border-primary bg-primary/10 text-foreground"
                    : "border-input text-muted-foreground hover:bg-accent",
                )}
                style={
                  active
                    ? { borderColor: tag.color, backgroundColor: `${tag.color}22` }
                    : undefined
                }
                data-testid={`search-filter-tag-${tag.id}`}
              >
                {tag.title}
              </button>
            );
          })}
        </div>
      )}

      {activeChips.length > 0 && (
        <div
          className="flex flex-wrap items-center gap-2 border-t pt-2"
          data-testid="search-active-chips"
        >
          {activeChips.map((chip) => (
            <Badge
              key={chip.key}
              variant="secondary"
              className="gap-1 pr-1"
              data-testid={`search-chip-${chip.key}`}
            >
              {chip.label}
              <button
                type="button"
                onClick={chip.clear}
                aria-label={`Clear ${chip.label}`}
                className="ml-1 rounded-sm hover:bg-muted"
              >
                <X className="h-3 w-3" />
              </button>
            </Badge>
          ))}
        </div>
      )}
    </div>
  );
};

const SegmentedStatus = ({
  value,
  onChange,
}: {
  value: Status;
  onChange: (next: Status) => void;
}) => {
  const options: { value: Status; label: string }[] = [
    { value: "", label: "All" },
    { value: "open", label: "Open" },
    { value: "completed", label: "Completed" },
  ];
  return (
    <div className="inline-flex rounded-md border" role="radiogroup" aria-label="Status">
      {options.map((opt) => (
        <button
          key={opt.value || "all"}
          type="button"
          role="radio"
          aria-checked={value === opt.value}
          onClick={() => onChange(opt.value)}
          className={cn(
            "px-3 py-1 text-xs first:rounded-l-md last:rounded-r-md",
            value === opt.value
              ? "bg-primary text-primary-foreground"
              : "hover:bg-accent",
          )}
          data-testid={`search-status-${opt.value || "all"}`}
        >
          {opt.label}
        </button>
      ))}
    </div>
  );
};

const DueDateRange = ({
  from,
  to,
  onChange,
}: {
  from: string;
  to: string;
  onChange: (from: string, to: string) => void;
}) => (
  <div className="inline-flex items-center gap-1 text-xs">
    <CalendarDays className="h-4 w-4 text-muted-foreground" aria-hidden />
    <Label htmlFor="search-due-from" className="sr-only">
      Due from
    </Label>
    <Input
      id="search-due-from"
      type="date"
      value={from}
      onChange={(e) => onChange(e.target.value, to)}
      className="h-7 w-36 text-xs"
      data-testid="search-due-from"
    />
    <span className="text-muted-foreground">to</span>
    <Label htmlFor="search-due-to" className="sr-only">
      Due to
    </Label>
    <Input
      id="search-due-to"
      type="date"
      value={to}
      onChange={(e) => onChange(from, e.target.value)}
      className="h-7 w-36 text-xs"
      data-testid="search-due-to"
    />
  </div>
);

const ProjectDropdown = ({
  value,
  options,
  onChange,
}: {
  value: string;
  options: ProjectRef[];
  onChange: (next: string) => void;
}) => (
  <div className="inline-flex items-center gap-1 text-xs">
    <Label htmlFor="search-project" className="text-muted-foreground">
      Project
    </Label>
    <select
      id="search-project"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="h-7 rounded-md border border-input bg-background px-2 text-xs"
      data-testid="search-project"
    >
      <option value="">Any</option>
      {options.map((p) => (
        <option key={p["@id"]} value={p["@id"]}>
          {p.name}
        </option>
      ))}
    </select>
  </div>
);

const AssigneeDropdown = ({
  value,
  options,
  onChange,
}: {
  value: string;
  options: AssigneeRef[];
  onChange: (next: string) => void;
}) => (
  <div className="inline-flex items-center gap-1 text-xs">
    <Label htmlFor="search-assignee" className="text-muted-foreground">
      Assignee
    </Label>
    <select
      id="search-assignee"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="h-7 rounded-md border border-input bg-background px-2 text-xs"
      data-testid="search-assignee"
    >
      <option value="">Anyone</option>
      {options.map((a) => (
        <option key={a["@id"]} value={a["@id"]}>
          {assigneeLabel(a)}
        </option>
      ))}
    </select>
  </div>
);

const assigneeLabel = (a: AssigneeRef): string => {
  const nick = a.nickname?.trim();
  if (nick) return nick;
  const full = [a.givenName, a.familyName]
    .map((part) => part?.trim() ?? "")
    .filter(Boolean)
    .join(" ");
  return full || a.email;
};

const SortDropdown = ({
  value,
  onChange,
}: {
  value: SortKey;
  onChange: (next: SortKey) => void;
}) => (
  <div className="ml-auto inline-flex items-center gap-1 text-xs">
    <Label htmlFor="search-sort" className="text-muted-foreground">
      Sort
    </Label>
    <select
      id="search-sort"
      value={value}
      onChange={(e) => onChange(e.target.value as SortKey)}
      className="h-7 rounded-md border border-input bg-background px-2 text-xs"
      data-testid="search-sort"
    >
      {Object.entries(SORT_LABELS).map(([key, label]) => (
        <option key={key} value={key}>
          {label}
        </option>
      ))}
    </select>
  </div>
);

const KIND_TABS: { value: Kind; label: string }[] = [
  { value: "tasks", label: "Tasks" },
  { value: "projects", label: "Projects" },
  { value: "discussions", label: "Discussions" },
];

const KindTabs = ({
  value,
  onChange,
}: {
  value: Kind;
  onChange: (next: Kind) => void;
}) => (
  <div
    className="inline-flex rounded-md border bg-background"
    role="tablist"
    aria-label="Search across"
    data-testid="search-kind-tabs"
  >
    {KIND_TABS.map((tab) => (
      <button
        key={tab.value}
        type="button"
        role="tab"
        aria-selected={value === tab.value}
        onClick={() => onChange(tab.value)}
        className={cn(
          "px-3 py-1 text-sm first:rounded-l-md last:rounded-r-md",
          value === tab.value
            ? "bg-primary text-primary-foreground"
            : "hover:bg-accent",
        )}
        data-testid={`search-kind-${tab.value}`}
      >
        {tab.label}
      </button>
    ))}
  </div>
);

interface ResultsProps {
  filters: FilterState;
  onPageChange: (page: number) => void;
}

const TaskResults = ({ filters, onPageChange }: ResultsProps) => {
  const [hits, setHits] = useState<TaskHit[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/tasks?${buildQuery(filters).toString()}`,
          {
            credentials: "include",
            signal: controller.signal,
            headers: { Accept: "application/ld+json" },
          },
        );
        if (!res.ok) throw new Error("Search failed.");
        const data = await res.json();
        setHits((data["hydra:member"] ?? data.member ?? []) as TaskHit[]);
        setTotal(data["hydra:totalItems"] ?? data.totalItems ?? 0);
      } catch (err) {
        if ((err as { name?: string })?.name === "AbortError") return;
        setError(err instanceof Error ? err.message : "Search failed.");
        setHits([]);
        setTotal(0);
      } finally {
        setLoading(false);
      }
    })();
    return () => controller.abort();
  }, [filters]);

  if (loading && hits.length === 0) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" /> Searching…
      </div>
    );
  }

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }

  if (hits.length === 0) {
    return (
      <div className="rounded-md border bg-background p-8 text-center text-sm text-muted-foreground" data-testid="search-empty">
        {filters.q.trim().length === 0 ? (
          <>Enter a keyword above to search across your tasks.</>
        ) : (
          <>No tasks match your search. Try different keywords or remove some filters.</>
        )}
      </div>
    );
  }

  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  return (
    <div className="space-y-3">
      <p className="text-xs text-muted-foreground" data-testid="search-result-count">
        {total} result{total === 1 ? "" : "s"}
      </p>
      <ul className="space-y-2" data-testid="search-results">
        {hits.map((hit) => (
          <ResultRow key={hit["@id"]} hit={hit} query={filters.q} />
        ))}
      </ul>
      {totalPages > 1 && (
        <Pagination
          page={filters.page}
          totalPages={totalPages}
          onChange={onPageChange}
        />
      )}
    </div>
  );
};

const ResultRow = ({ hit, query }: { hit: TaskHit; query: string }) => {
  const completed = Boolean(hit.completedOn);
  return (
    <li
      id={encodeURIComponent(hit["@id"])}
      data-testid="search-result-row"
      className="rounded-md border bg-background p-3 transition-colors hover:bg-accent/30 target:ring-2 target:ring-primary"
    >
      <Link
        href={{ pathname: "/tasks", query: { search: query } }}
        className="block no-underline"
      >
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <div
              className={cn(
                "text-sm font-medium",
                completed && "line-through text-muted-foreground",
              )}
            >
              {highlightMatches(hit.title, query)}
            </div>
            {hit.description && (
              <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                {highlightMatches(snippetOf(hit.description), query)}
              </p>
            )}
            {hit.tags && hit.tags.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1">
                {hit.tags.map((tag) => (
                  <Badge
                    key={tag["@id"]}
                    variant="outline"
                    style={{ borderColor: tag.color, color: tag.color }}
                    className="text-[10px] py-0"
                  >
                    {tag.title}
                  </Badge>
                ))}
              </div>
            )}
          </div>
          <div className="flex flex-col items-end gap-1 text-xs text-muted-foreground">
            {hit.dueDate && (
              <span>Due {hit.dueDate.slice(0, 10)}</span>
            )}
            <span>{completed ? "Completed" : "Open"}</span>
          </div>
        </div>
      </Link>
    </li>
  );
};

const Pagination = ({
  page,
  totalPages,
  onChange,
}: {
  page: number;
  totalPages: number;
  onChange: (page: number) => void;
}) => (
  <div className="flex items-center justify-center gap-2 pt-2" data-testid="search-pagination">
    <Button
      variant="outline"
      size="sm"
      disabled={page <= 1}
      onClick={() => onChange(page - 1)}
    >
      <ChevronLeft className="h-4 w-4" /> Prev
    </Button>
    <span className="text-xs text-muted-foreground">
      Page {page} of {totalPages}
    </span>
    <Button
      variant="outline"
      size="sm"
      disabled={page >= totalPages}
      onClick={() => onChange(page + 1)}
    >
      Next <ChevronRight className="h-4 w-4" />
    </Button>
  </div>
);

const SNIPPET_MAX = 200;
const snippetOf = (s: string): string =>
  s.length > SNIPPET_MAX ? `${s.slice(0, SNIPPET_MAX).trimEnd()}…` : s;

const ProjectResults = ({ filters, onPageChange }: ResultsProps) => {
  const [hits, setHits] = useState<ProjectHit[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    void (async () => {
      try {
        const params = new URLSearchParams();
        if (filters.q) params.set("search", filters.q);
        params.set("page", String(filters.page));
        params.set("itemsPerPage", String(PAGE_SIZE));
        const res = await fetch(
          `${ENTRYPOINT}/projects?${params.toString()}`,
          {
            credentials: "include",
            signal: controller.signal,
            headers: { Accept: "application/ld+json" },
          },
        );
        if (!res.ok) throw new Error("Search failed.");
        const data = await res.json();
        setHits((data["hydra:member"] ?? data.member ?? []) as ProjectHit[]);
        setTotal(data["hydra:totalItems"] ?? data.totalItems ?? 0);
      } catch (err) {
        if ((err as { name?: string })?.name === "AbortError") return;
        setError(err instanceof Error ? err.message : "Search failed.");
        setHits([]);
        setTotal(0);
      } finally {
        setLoading(false);
      }
    })();
    return () => controller.abort();
  }, [filters.q, filters.page]);

  if (loading && hits.length === 0) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" /> Searching…
      </div>
    );
  }

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }

  if (hits.length === 0) {
    return (
      <div
        className="rounded-md border bg-background p-8 text-center text-sm text-muted-foreground"
        data-testid="search-empty"
      >
        {filters.q.trim().length === 0
          ? "Enter a keyword above to search across your projects."
          : "No projects match your search."}
      </div>
    );
  }

  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  return (
    <div className="space-y-3">
      <p
        className="text-xs text-muted-foreground"
        data-testid="search-result-count"
      >
        {total} result{total === 1 ? "" : "s"}
      </p>
      <ul className="space-y-2" data-testid="search-results">
        {hits.map((hit) => (
          <li
            key={hit["@id"]}
            id={encodeURIComponent(hit["@id"])}
            data-testid="search-result-row"
            className="rounded-md border bg-background p-3 transition-colors hover:bg-accent/30 target:ring-2 target:ring-primary"
          >
            <Link
              href={`/projects/${hit.id}`}
              className="block no-underline"
            >
              <div className="text-sm font-medium">
                {highlightMatches(hit.title, filters.q)}
              </div>
              {hit.description && (
                <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                  {highlightMatches(snippetOf(hit.description), filters.q)}
                </p>
              )}
            </Link>
          </li>
        ))}
      </ul>
      {totalPages > 1 && (
        <Pagination
          page={filters.page}
          totalPages={totalPages}
          onChange={onPageChange}
        />
      )}
    </div>
  );
};

const DiscussionResults = ({ filters, onPageChange }: ResultsProps) => {
  const [hits, setHits] = useState<DiscussionHit[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    void (async () => {
      try {
        const params = new URLSearchParams();
        if (filters.q) params.set("search", filters.q);
        params.set("page", String(filters.page));
        params.set("itemsPerPage", String(PAGE_SIZE));
        const res = await fetch(
          `${ENTRYPOINT}/discussions?${params.toString()}`,
          {
            credentials: "include",
            signal: controller.signal,
            headers: { Accept: "application/ld+json" },
          },
        );
        if (!res.ok) throw new Error("Search failed.");
        const data = await res.json();
        setHits((data["hydra:member"] ?? data.member ?? []) as DiscussionHit[]);
        setTotal(data["hydra:totalItems"] ?? data.totalItems ?? 0);
      } catch (err) {
        if ((err as { name?: string })?.name === "AbortError") return;
        setError(err instanceof Error ? err.message : "Search failed.");
        setHits([]);
        setTotal(0);
      } finally {
        setLoading(false);
      }
    })();
    return () => controller.abort();
  }, [filters.q, filters.page]);

  if (loading && hits.length === 0) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" /> Searching…
      </div>
    );
  }

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertDescription>{error}</AlertDescription>
      </Alert>
    );
  }

  if (hits.length === 0) {
    return (
      <div
        className="rounded-md border bg-background p-8 text-center text-sm text-muted-foreground"
        data-testid="search-empty"
      >
        {filters.q.trim().length === 0
          ? "Enter a keyword above to search across your discussions."
          : "No discussions match your search."}
      </div>
    );
  }

  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  return (
    <div className="space-y-3">
      <p
        className="text-xs text-muted-foreground"
        data-testid="search-result-count"
      >
        {total} result{total === 1 ? "" : "s"}
      </p>
      <ul className="space-y-2" data-testid="search-results">
        {hits.map((hit) => (
          <li
            key={hit["@id"]}
            id={encodeURIComponent(hit["@id"])}
            data-testid="search-result-row"
            className="rounded-md border bg-background p-3 transition-colors hover:bg-accent/30 target:ring-2 target:ring-primary"
          >
            <Link
              href={`/discussions/${hit.id}`}
              className="block no-underline"
            >
              <div className="text-sm font-medium">
                {highlightMatches(hit.title, filters.q)}
              </div>
              <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                {highlightMatches(snippetOf(hit.body), filters.q)}
              </p>
              <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                {hit.isPinned && (
                  <Badge variant="secondary" className="text-[10px] py-0">
                    Pinned
                  </Badge>
                )}
                {hit.isLocked && (
                  <Badge variant="secondary" className="text-[10px] py-0">
                    Locked
                  </Badge>
                )}
              </div>
            </Link>
          </li>
        ))}
      </ul>
      {totalPages > 1 && (
        <Pagination
          page={filters.page}
          totalPages={totalPages}
          onChange={onPageChange}
        />
      )}
    </div>
  );
};

function readFilters(query: Record<string, string | string[] | undefined>): FilterState {
  const stringQ = (key: string): string =>
    typeof query[key] === "string" ? (query[key] as string) : "";
  const tags = query.tags;
  const tagList = Array.isArray(tags)
    ? tags
    : typeof tags === "string" && tags.length > 0
      ? [tags]
      : [];
  const status = stringQ("status");
  const sort = (stringQ("sort") || "relevance") as SortKey;
  const page = Number.parseInt(stringQ("page") || "1", 10);
  const kindRaw = stringQ("kind");
  const kind: Kind =
    kindRaw === "projects" || kindRaw === "discussions" ? kindRaw : "tasks";
  return {
    q: stringQ("q"),
    kind,
    status: status === "open" || status === "completed" ? status : "",
    dueFrom: stringQ("dueFrom"),
    dueTo: stringQ("dueTo"),
    project: stringQ("project"),
    assignee: stringQ("assignee"),
    tags: tagList,
    sort: SORT_LABELS[sort] ? sort : "relevance",
    page: Number.isFinite(page) && page > 0 ? page : 1,
  };
}

function writeFilters(filters: FilterState): Record<string, string | string[]> {
  const out: Record<string, string | string[]> = {};
  if (filters.q) out.q = filters.q;
  if (filters.kind !== "tasks") out.kind = filters.kind;
  // The task-specific filters are meaningless on the project / discussion
  // tabs; only persist them when we're on the tasks tab so toggling tabs
  // doesn't carry stale chips into a URL the back button can land on.
  if (filters.kind === "tasks") {
    if (filters.status) out.status = filters.status;
    if (filters.dueFrom) out.dueFrom = filters.dueFrom;
    if (filters.dueTo) out.dueTo = filters.dueTo;
    if (filters.project) out.project = filters.project;
    if (filters.assignee) out.assignee = filters.assignee;
    if (filters.tags.length > 0) out.tags = filters.tags;
    if (filters.sort !== "relevance") out.sort = filters.sort;
  }
  if (filters.page > 1) out.page = String(filters.page);
  return out;
}

function buildQuery(filters: FilterState): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.q) params.set("search", filters.q);
  if (filters.status) params.set("status", filters.status);
  if (filters.dueFrom) params.set("dueDate[after]", filters.dueFrom);
  if (filters.dueTo) params.set("dueDate[before]", filters.dueTo);
  if (filters.project) params.set("project", filters.project);
  // The Task SearchFilter exposes the relation as `assignees` (plural) —
  // a single value still narrows to tasks where that user is assigned.
  if (filters.assignee) params.set("assignees", filters.assignee);
  filters.tags.forEach((iri) => params.append("tags[]", iri));
  // Map UI sort to API Platform OrderFilter syntax. Falling back to
  // the filter's relevance ORDER BY when sort is "relevance" — the
  // server-side filter installs that automatically.
  if (filters.sort !== "relevance") {
    const [field, dir] = filters.sort.split(":");
    params.set(`order[${field}]`, dir);
  }
  params.set("page", String(filters.page));
  params.set("itemsPerPage", String(PAGE_SIZE));
  return params;
}

export default SearchPage;
