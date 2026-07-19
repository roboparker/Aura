import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  ChevronRight,
  Globe,
  Loader2,
  Plus,
  Search as SearchIcon,
  X,
} from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace, type Space } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { highlightMatches } from "@/lib/highlightMatches";
import { parseQuery } from "@/lib/searchQuery";
import { addRecentSearch } from "@/lib/recentSearches";
import { displayName } from "@/lib/userDisplay";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

// ---- Types ----
type Status = "" | "open" | "completed";
type Sort = "relevance" | "recent";
type Kind = "tasks" | "boards";

interface SearchMatch {
  source: string;
  label: string;
  snippet: string;
}
interface TagRef {
  "@id": string;
  id: string;
  title: string;
  color: string;
}
interface TaskHit {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  completedOn: string | null;
  dueDate: string | null;
  board: string | null;
  tags?: TagRef[];
  searchMatches?: SearchMatch[];
}
interface BoardHit {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
}
interface AssignableUser {
  "@id": string;
  id: string;
  email: string;
  givenName?: string | null;
  familyName?: string | null;
  nickname?: string | null;
}
interface BoardRef {
  "@id": string;
  id: string;
  title: string;
}

interface FilterState {
  q: string;
  kind: Kind;
  status: Status;
  overdue: boolean;
  dueFrom: string;
  dueTo: string;
  board: string;
  assignee: string;
  tags: string[];
  sort: Sort;
  scope: "active" | "all";
  page: number;
}

const PAGE_SIZE = 20;
const KINDS: Kind[] = ["tasks", "boards"];
const KIND_LABEL: Record<Kind, string> = {
  tasks: "Tasks",
  boards: "Boards",
};

const collectionMembers = <T,>(data: {
  member?: T[];
  "hydra:member"?: T[];
}): T[] => data.member ?? data["hydra:member"] ?? [];
const collectionTotal = (data: {
  totalItems?: number;
  "hydra:totalItems"?: number;
}): number => data.totalItems ?? data["hydra:totalItems"] ?? 0;

const snippet = (text: string): string => {
  const clean = text.replace(/\s+/g, " ").trim();
  return clean.length > 200 ? `${clean.slice(0, 197)}…` : clean;
};

// ---- URL state ----
const asString = (v: unknown): string => (typeof v === "string" ? v : "");
const readFilters = (q: Record<string, unknown>): FilterState => {
  const kindRaw = asString(q.kind);
  const tagsRaw = q.tags;
  const tags = Array.isArray(tagsRaw)
    ? tagsRaw.filter((t): t is string => typeof t === "string")
    : typeof tagsRaw === "string"
      ? [tagsRaw]
      : [];
  return {
    q: asString(q.q),
    kind: (["tasks", "boards"] as readonly string[]).includes(kindRaw)
      ? (kindRaw as Kind)
      : "tasks",
    status: (["open", "completed"] as readonly string[]).includes(asString(q.status))
      ? (asString(q.status) as Status)
      : "",
    overdue: q.overdue === "true",
    dueFrom: asString(q.dueFrom),
    dueTo: asString(q.dueTo),
    board: asString(q.board),
    assignee: asString(q.assignee),
    tags,
    sort: asString(q.sort) === "recent" ? "recent" : "relevance",
    scope: asString(q.scope) === "all" ? "all" : "active",
    page: Math.max(1, parseInt(asString(q.page) || "1", 10) || 1),
  };
};
const writeFilters = (f: FilterState): Record<string, string | string[]> => {
  const out: Record<string, string | string[]> = {};
  if (f.q) out.q = f.q;
  if (f.kind !== "tasks") out.kind = f.kind;
  if (f.sort !== "relevance") out.sort = f.sort;
  if (f.scope !== "active") out.scope = f.scope;
  if (f.page > 1) out.page = String(f.page);
  if (f.kind === "tasks") {
    if (f.status) out.status = f.status;
    if (f.overdue) out.overdue = "true";
    if (f.dueFrom) out.dueFrom = f.dueFrom;
    if (f.dueTo) out.dueTo = f.dueTo;
    if (f.board) out.board = f.board;
    if (f.assignee) out.assignee = f.assignee;
    if (f.tags.length) out.tags = f.tags;
  }
  return out;
};

/**
 * The space IRI to scope a given kind to, or null for global. Tasks are
 * owner-scoped and frequently standalone (no board → no space), so the
 * personal space never scopes tasks — that would hide every personal
 * task. Shared spaces scope tasks through `board.space`. Boards are
 * space-owned, so they scope to any active space.
 */
const spaceFor = (kind: Kind, f: FilterState, activeSpace: Space | null): string | null => {
  if (f.scope !== "active" || !activeSpace) return null;
  if (kind === "tasks" && activeSpace.isPersonal) return null;
  return activeSpace["@id"];
};

const buildUrl = (
  kind: Kind,
  f: FilterState,
  activeSpace: Space | null,
  page: number,
  itemsPerPage: number,
): string => {
  const space = spaceFor(kind, f, activeSpace);
  const p = new URLSearchParams();
  if (f.q) p.set("search", f.q);
  if (f.sort === "recent") p.set("sort", "recent");
  p.set("page", String(page));
  p.set("itemsPerPage", String(itemsPerPage));
  if (kind === "tasks") {
    if (f.status) p.set("status", f.status);
    if (f.overdue) p.set("overdue", "true");
    if (f.dueFrom) p.set("dueDate[after]", f.dueFrom);
    if (f.dueTo) p.set("dueDate[before]", f.dueTo);
    if (f.board) p.set("board", f.board);
    if (f.assignee) p.set("assignees", f.assignee);
    f.tags.forEach((t) => p.append("tags[]", t));
    if (space) p.set("board.space", space);
    return `${ENTRYPOINT}/tasks?${p.toString()}`;
  }
  if (space) p.set("space", space);
  return `${ENTRYPOINT}/${kind}?${p.toString()}`;
};

const SearchPage = () => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const { activeSpace, spaces } = useActiveSpace();
  const router = useRouter();
  const filters = useMemo<FilterState>(() => readFilters(router.query), [router.query]);

  const [tags, setTags] = useState<TagRef[]>([]);
  const [boards, setBoards] = useState<BoardRef[]>([]);
  const [assignables, setAssignables] = useState<AssignableUser[]>([]);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  useEffect(() => {
    if (!isAuthenticated) return;
    void Promise.all([
      fetch(`${ENTRYPOINT}/tags?itemsPerPage=100`, { credentials: "include" }),
      fetch(`${ENTRYPOINT}/boards?itemsPerPage=100`, { credentials: "include" }),
      fetch(`${ENTRYPOINT}/me/assignable-users`, { credentials: "include" }),
    ]).then(async ([t, p, a]) => {
      if (t.ok) setTags(collectionMembers<TagRef>(await t.json()));
      if (p.ok) setBoards(collectionMembers<BoardRef>(await p.json()));
      if (a.ok) {
        const data = await a.json();
        setAssignables(Array.isArray(data) ? data : collectionMembers<AssignableUser>(data));
      }
    });
  }, [isAuthenticated]);

  const boardName = useMemo(() => {
    const map = new Map<string, string>();
    boards.forEach((p) => map.set(p["@id"], p.title));
    return map;
  }, [boards]);

  const replaceFilters = useCallback(
    (patch: Partial<FilterState>) => {
      const next = { ...filters, ...patch };
      const resetsPage = Object.keys(patch).some((k) => k !== "page");
      if (resetsPage && patch.page === undefined) next.page = 1;
      void router.replace(
        { pathname: "/search", query: writeFilters(next) },
        undefined,
        { shallow: false },
      );
    },
    [filters, router],
  );

  const submitQuery = useCallback(
    (raw: string) => {
      const parsed = parseQuery(raw);
      const patch: Partial<FilterState> = { q: parsed.text };
      if (parsed.status) patch.status = parsed.status;
      if (parsed.tags.length) {
        const ids = parsed.tags
          .map((label) => tags.find((t) => t.title.toLowerCase() === label.toLowerCase())?.["@id"])
          .filter((x): x is string => Boolean(x));
        if (ids.length) patch.tags = [...new Set([...filters.tags, ...ids])];
      }
      if (parsed.assignee) {
        const handle = parsed.assignee.toLowerCase();
        const match = assignables.find(
          (u) =>
            u.email.split("@")[0].toLowerCase() === handle ||
            displayName(u).toLowerCase().includes(handle),
        );
        if (match) patch.assignee = match["@id"];
      }
      replaceFilters(patch);
      if (parsed.text) addRecentSearch(parsed.text);
    },
    [tags, assignables, filters.tags, replaceFilters],
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
        <title>{filters.q ? `Search: ${filters.q}` : "Search"} - Madori</title>
      </Head>
      <main className="min-h-screen bg-background">
        <div className="mx-auto max-w-5xl space-y-5 px-4 py-8">
          <SearchHeader
            filters={filters}
            activeSpace={activeSpace}
            onSubmit={submitQuery}
            onScope={(scope) => replaceFilters({ scope })}
          />

          <KindTabs
            value={filters.kind}
            onChange={(kind) => replaceFilters({ kind })}
            filters={filters}
            activeSpace={activeSpace}
          />

          <div className="flex items-start justify-between gap-2">
            {filters.kind === "tasks" ? (
              <FilterBar
                filters={filters}
                tags={tags}
                boards={boards}
                assignables={assignables}
                onChange={replaceFilters}
              />
            ) : (
              <span />
            )}
            <SortToggle value={filters.sort} onChange={(sort) => replaceFilters({ sort })} />
          </div>

          {filters.kind === "tasks" && (
            <TaskResults
              filters={filters}
              boardName={boardName}
              spaces={spaces}
              activeSpace={activeSpace}
              onPage={(page) => replaceFilters({ page })}
              onScope={(scope) => replaceFilters({ scope })}
              onClearFilters={() =>
                replaceFilters({
                  status: "",
                  overdue: false,
                  dueFrom: "",
                  dueTo: "",
                  board: "",
                  assignee: "",
                  tags: [],
                })
              }
            />
          )}
          {filters.kind === "boards" && (
            <BoardResults filters={filters} activeSpace={activeSpace} onPage={(page) => replaceFilters({ page })} />
          )}
        </div>
      </main>
    </>
  );
};

// ---- Header ----
const SearchHeader = ({
  filters,
  activeSpace,
  onSubmit,
  onScope,
}: {
  filters: FilterState;
  activeSpace: Space | null;
  onSubmit: (raw: string) => void;
  onScope: (scope: "active" | "all") => void;
}) => {
  const router = useRouter();
  const [draft, setDraft] = useState(filters.q);
  useEffect(() => setDraft(filters.q), [filters.q]);

  return (
    <header className="space-y-3">
      <div className="flex items-center gap-1 text-sm text-muted-foreground">
        <SearchIcon className="h-3.5 w-3.5" />
        <span>Search</span>
        {filters.q && (
          <>
            <ChevronRight className="h-3.5 w-3.5" />
            <span className="font-mono text-foreground">&ldquo;{filters.q}&rdquo;</span>
          </>
        )}
      </div>

      <form
        onSubmit={(e) => {
          e.preventDefault();
          onSubmit(draft);
        }}
        className="relative"
      >
        <SearchIcon className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Escape") router.back();
          }}
          placeholder={activeSpace ? `Search ${activeSpace.name}…` : "Search…"}
          className="h-12 rounded-xl pl-11 pr-44 text-base"
          autoFocus
          data-testid="search-page-input"
        />
        <div className="absolute right-2 top-1/2 -translate-y-1/2">
          <button
            type="button"
            onClick={() => onScope(filters.scope === "active" ? "all" : "active")}
            className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground hover:text-foreground"
            title={
              filters.scope === "active"
                ? "Searching the active space — click to widen to all spaces"
                : "Searching all spaces — click to scope to the active space"
            }
            data-testid="search-scope"
          >
            {filters.scope === "active" ? (
              activeSpace?.name ?? "Active space"
            ) : (
              <>
                <Globe className="h-3 w-3" /> All spaces
              </>
            )}
          </button>
        </div>
      </form>
    </header>
  );
};

// ---- Tabs with counts ----
const KindTabs = ({
  value,
  onChange,
  filters,
  activeSpace,
}: {
  value: Kind;
  onChange: (k: Kind) => void;
  filters: FilterState;
  activeSpace: Space | null;
}) => {
  const [counts, setCounts] = useState<Record<Kind, number | null>>({
    tasks: null,
    boards: null,
  });

  useEffect(() => {
    let live = true;
    void Promise.all(
      KINDS.map(async (k) => {
        try {
          const res = await fetch(buildUrl(k, filters, activeSpace, 1, 1), {
            credentials: "include",
            headers: { Accept: "application/ld+json" },
          });
          return [k, res.ok ? collectionTotal(await res.json()) : 0] as const;
        } catch {
          return [k, 0] as const;
        }
      }),
    ).then((entries) => {
      if (live) setCounts(Object.fromEntries(entries) as Record<Kind, number>);
    });
    return () => {
      live = false;
    };
  }, [filters, activeSpace]);

  return (
    <div className="flex items-center gap-4 border-b border-border" role="tablist" data-testid="search-kind-tabs">
      {KINDS.map((k) => (
        <button
          key={k}
          type="button"
          role="tab"
          aria-selected={value === k}
          onClick={() => onChange(k)}
          className={cn(
            "-mb-px flex items-center gap-1.5 border-b-2 px-1 py-2 text-sm font-medium transition-colors",
            value === k
              ? "border-primary text-foreground"
              : "border-transparent text-muted-foreground hover:text-foreground",
          )}
          data-testid={`search-kind-${k}`}
        >
          {KIND_LABEL[k]}
          {counts[k] !== null && (
            <span className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{counts[k]}</span>
          )}
        </button>
      ))}
      <span className="ml-auto hidden items-center gap-1 text-xs text-muted-foreground sm:flex">
        <kbd className="rounded border border-input bg-muted px-1.5 text-xs">tab</kbd> switch
      </span>
    </div>
  );
};

const SortToggle = ({ value, onChange }: { value: Sort; onChange: (s: Sort) => void }) => (
  <div className="flex shrink-0 items-center gap-1 text-xs" data-testid="search-sort">
    <span className="text-muted-foreground">Sort</span>
    {(["relevance", "recent"] as const).map((s) => (
      <button
        key={s}
        type="button"
        onClick={() => onChange(s)}
        className={cn(
          "rounded px-2 py-1 font-medium",
          value === s ? "bg-muted text-foreground" : "text-muted-foreground hover:text-foreground",
        )}
      >
        {s === "relevance" ? "Relevance" : "Most recent"}
      </button>
    ))}
  </div>
);

// ---- Filter bar (tasks) ----
const segClass = (active: boolean) =>
  cn(
    "rounded-full border px-2.5 py-1 text-xs font-medium transition-colors",
    active ? "border-primary bg-primary/10 text-foreground" : "border-input text-muted-foreground hover:text-foreground",
  );

const FilterBar = ({
  filters,
  tags,
  boards,
  assignables,
  onChange,
}: {
  filters: FilterState;
  tags: TagRef[];
  boards: BoardRef[];
  assignables: AssignableUser[];
  onChange: (patch: Partial<FilterState>) => void;
}) => {
  const proj = boards.find((p) => p["@id"] === filters.board);
  const assignee = assignables.find((a) => a["@id"] === filters.assignee);

  const chips: { key: string; label: string; clear: () => void }[] = [];
  if (filters.status) chips.push({ key: "status", label: `Status: ${filters.status}`, clear: () => onChange({ status: "" }) });
  if (filters.overdue) chips.push({ key: "overdue", label: "Overdue", clear: () => onChange({ overdue: false }) });
  if (filters.dueFrom || filters.dueTo) chips.push({ key: "due", label: "Due range", clear: () => onChange({ dueFrom: "", dueTo: "" }) });
  if (proj) chips.push({ key: "board", label: `Board: ${proj.title}`, clear: () => onChange({ board: "" }) });
  if (assignee) chips.push({ key: "assignee", label: `@${assignee.email.split("@")[0]}`, clear: () => onChange({ assignee: "" }) });
  filters.tags.forEach((iri) => {
    const t = tags.find((x) => x["@id"] === iri);
    chips.push({ key: `tag-${t?.id ?? iri}`, label: `tag: ${t?.title ?? "…"}`, clear: () => onChange({ tags: filters.tags.filter((x) => x !== iri) }) });
  });

  return (
    <div className="min-w-0 flex-1 space-y-2" data-testid="search-filter-bar">
      <div className="flex flex-wrap items-center gap-1.5">
        {/* Status segmented */}
        <div className="inline-flex items-center gap-1">
          {([["", "All"], ["open", "Open"], ["completed", "Completed"]] as const).map(([v, label]) => (
            <button
              key={v || "all"}
              type="button"
              onClick={() => onChange({ status: v })}
              className={segClass(filters.status === v)}
              data-testid={`search-status-${v || "all"}`}
            >
              {label}
            </button>
          ))}
        </div>
        <button
          type="button"
          onClick={() => onChange({ overdue: !filters.overdue })}
          className={segClass(filters.overdue)}
          data-testid="search-overdue"
        >
          Overdue
        </button>
        <input
          type="date"
          value={filters.dueFrom}
          onChange={(e) => onChange({ dueFrom: e.target.value })}
          className="rounded-full border border-input bg-background px-2.5 py-1 text-xs text-muted-foreground"
          aria-label="Due after"
          data-testid="search-due-from"
        />
        <input
          type="date"
          value={filters.dueTo}
          onChange={(e) => onChange({ dueTo: e.target.value })}
          className="rounded-full border border-input bg-background px-2.5 py-1 text-xs text-muted-foreground"
          aria-label="Due before"
          data-testid="search-due-to"
        />
        <select
          value={filters.board}
          onChange={(e) => onChange({ board: e.target.value })}
          className={segClass(Boolean(filters.board))}
          aria-label="Board"
          data-testid="search-board"
        >
          <option value="">Board</option>
          {boards.map((p) => (
            <option key={p["@id"]} value={p["@id"]}>
              {p.title}
            </option>
          ))}
        </select>
        <select
          value={filters.assignee}
          onChange={(e) => onChange({ assignee: e.target.value })}
          className={segClass(Boolean(filters.assignee))}
          aria-label="Assignee"
          data-testid="search-assignee"
        >
          <option value="">Assignee</option>
          {assignables.map((u) => (
            <option key={u["@id"]} value={u["@id"]}>
              {displayName(u)}
            </option>
          ))}
        </select>
      </div>

      {tags.length > 0 && (
        <div className="flex flex-wrap items-center gap-1">
          {tags.map((t) => {
            const on = filters.tags.includes(t["@id"]);
            return (
              <button
                key={t["@id"]}
                type="button"
                onClick={() =>
                  onChange({ tags: on ? filters.tags.filter((x) => x !== t["@id"]) : [...filters.tags, t["@id"]] })
                }
                className={segClass(on)}
                data-testid={`search-filter-tag-${t.id}`}
              >
                {t.title}
              </button>
            );
          })}
        </div>
      )}

      {chips.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 border-t border-border pt-2" data-testid="search-active-chips">
          {chips.map((chip) => (
            <Badge key={chip.key} variant="secondary" className="gap-1 pr-1" data-testid={`search-chip-${chip.key}`}>
              {chip.label}
              <button type="button" onClick={chip.clear} aria-label={`Clear ${chip.label}`} className="ml-1 rounded-sm hover:bg-muted">
                <X className="h-3 w-3" />
              </button>
            </Badge>
          ))}
        </div>
      )}
    </div>
  );
};

// ---- Generic result hook ----
function useResults<T>(url: string, enabled: boolean) {
  const [items, setItems] = useState<T[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    if (!enabled) return;
    let live = true;
    setLoading(true);
    fetch(url, { credentials: "include", headers: { Accept: "application/ld+json" } })
      .then(async (res) => {
        if (!res.ok || !live) return;
        const data = await res.json();
        setItems(collectionMembers<T>(data));
        setTotal(collectionTotal(data));
      })
      .catch(() => {})
      .finally(() => {
        if (live) setLoading(false);
      });
    return () => {
      live = false;
    };
  }, [url, enabled]);
  return { items, total, loading };
}

const ResultShell = ({
  loading,
  count,
  total,
  page,
  onPage,
  empty,
  children,
}: {
  loading: boolean;
  count: number;
  total: number;
  page: number;
  onPage: (p: number) => void;
  empty: React.ReactNode;
  children: React.ReactNode;
}) => {
  if (loading && count === 0) {
    return (
      <div className="flex items-center gap-2 py-10 text-sm text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" /> Searching…
      </div>
    );
  }
  if (count === 0) return <>{empty}</>;
  const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
  return (
    <div className="space-y-3">
      <p className="text-xs text-muted-foreground" data-testid="search-result-count">
        {total} result{total === 1 ? "" : "s"}
      </p>
      <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-background" data-testid="search-results">
        {children}
      </ul>
      {pages > 1 && (
        <div className="flex items-center justify-center gap-3 text-sm" data-testid="search-pagination">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => onPage(page - 1)}>
            Previous
          </Button>
          <span className="text-muted-foreground">
            Page {page} of {pages}
          </span>
          <Button size="sm" variant="outline" disabled={page >= pages} onClick={() => onPage(page + 1)}>
            Next
          </Button>
        </div>
      )}
    </div>
  );
};

// ---- Task results ----
const TaskResults = ({
  filters,
  boardName,
  spaces,
  activeSpace,
  onPage,
  onScope,
  onClearFilters,
}: {
  filters: FilterState;
  boardName: Map<string, string>;
  spaces: Space[];
  activeSpace: Space | null;
  onPage: (p: number) => void;
  onScope: (s: "active" | "all") => void;
  onClearFilters: () => void;
}) => {
  const url = buildUrl("tasks", filters, activeSpace, filters.page, PAGE_SIZE);
  const { items, total, loading } = useResults<TaskHit>(url, true);
  return (
    <ResultShell
      loading={loading}
      count={items.length}
      total={total}
      page={filters.page}
      onPage={onPage}
      empty={
        <EmptyState filters={filters} spaces={spaces} activeSpace={activeSpace} onScope={onScope} onClearFilters={onClearFilters} />
      }
    >
      {items.map((t) => (
        <li key={t["@id"]} id={encodeURIComponent(t["@id"])} data-testid="search-result-row" className="target:bg-primary/5">
          <Link href={`/tasks?search=${encodeURIComponent(filters.q)}`} className="block px-4 py-3 no-underline transition-colors hover:bg-muted/40">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0 flex-1">
                {t.board && boardName.get(t.board) && (
                  <p className="font-mono text-xs text-muted-foreground">{boardName.get(t.board)}</p>
                )}
                <p className="text-sm font-medium text-foreground">
                  {highlightMatches(t.title, filters.q)}
                  {t.tags?.map((tag) => (
                    <Badge key={tag["@id"]} variant="outline" className="ml-1.5 align-middle text-xs">
                      {tag.title}
                    </Badge>
                  ))}
                </p>
                {t.description && (
                  <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                    {highlightMatches(snippet(t.description), filters.q)}
                  </p>
                )}
                {(t.searchMatches ?? []).map((m, i) => (
                  <p key={i} className="mt-1 line-clamp-1 text-xs text-cyan-700 dark:text-cyan-400">
                    matched in {m.source === "comment" ? `comment by ${m.label}` : `custom field ${m.label}`} —{" "}
                    <span className="text-muted-foreground">{highlightMatches(m.snippet, filters.q)}</span>
                  </p>
                ))}
              </div>
              <div className="shrink-0 text-right text-xs text-muted-foreground">
                {t.dueDate && <div>Due {new Date(t.dueDate).toLocaleDateString()}</div>}
                <div>{t.completedOn ? "completed" : "open"}</div>
              </div>
            </div>
          </Link>
        </li>
      ))}
    </ResultShell>
  );
};

// ---- Board results ----
const BoardResults = ({
  filters,
  activeSpace,
  onPage,
}: {
  filters: FilterState;
  activeSpace: Space | null;
  onPage: (p: number) => void;
}) => {
  const url = buildUrl("boards", filters, activeSpace, filters.page, PAGE_SIZE);
  const { items, total, loading } = useResults<BoardHit>(url, true);
  return (
    <ResultShell loading={loading} count={items.length} total={total} page={filters.page} onPage={onPage} empty={<SimpleEmpty q={filters.q} />}>
      {items.map((p) => (
        <li key={p["@id"]} data-testid="search-result-row">
          <Link href={`/boards/${p.id}`} className="block px-4 py-3 no-underline transition-colors hover:bg-muted/40">
            <p className="text-sm font-medium text-foreground">{highlightMatches(p.title, filters.q)}</p>
            {p.description && (
              <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{highlightMatches(snippet(p.description), filters.q)}</p>
            )}
          </Link>
        </li>
      ))}
    </ResultShell>
  );
};

// ---- Empty states ----
const SimpleEmpty = ({ q }: { q: string }) => (
  <div className="rounded-lg border border-border bg-background px-6 py-12 text-center" data-testid="search-empty">
    <span className="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-full bg-muted">
      <SearchIcon className="h-5 w-5 text-muted-foreground" />
    </span>
    <p className="mt-4 text-sm font-medium">{q ? `No results for “${q}”` : "Start typing to search"}</p>
  </div>
);

const EmptyState = ({
  filters,
  spaces,
  activeSpace,
  onScope,
  onClearFilters,
}: {
  filters: FilterState;
  spaces: Space[];
  activeSpace: Space | null;
  onScope: (s: "active" | "all") => void;
  onClearFilters: () => void;
}) => {
  const activeFilters = (
    [
      filters.status && "status",
      filters.overdue && "overdue",
      filters.board && "board",
      filters.assignee && "assignee",
      filters.tags.length > 0 && "tags",
    ].filter(Boolean) as string[]
  );
  const otherSpaces = spaces.filter((s) => s["@id"] !== activeSpace?.["@id"]);

  return (
    <div className="rounded-lg border border-border bg-background px-6 py-12 text-center" data-testid="search-empty">
      <span className="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-full bg-muted">
        <SearchIcon className="h-5 w-5 text-muted-foreground" />
      </span>
      <h3 className="mt-4 text-lg font-semibold">
        {filters.q ? `No results for “${filters.q}”` : "No tasks match these filters"}
      </h3>

      {activeFilters.length > 0 && (
        <div className="mx-auto mt-5 max-w-sm text-left">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Try removing a filter</p>
          <div className="mt-1.5 space-y-1">
            {activeFilters.map((f) => (
              <button
                key={f}
                type="button"
                onClick={onClearFilters}
                className="flex w-full items-center justify-between rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
              >
                <span className="capitalize">{f}</span>
                <span className="text-xs text-muted-foreground">× remove</span>
              </button>
            ))}
          </div>
        </div>
      )}

      {filters.scope === "active" && (
        <div className="mx-auto mt-5 max-w-sm text-left">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Or widen the scope</p>
          <button
            type="button"
            onClick={() => onScope("all")}
            className="mt-1.5 flex w-full items-center justify-between rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
            data-testid="search-widen-scope"
          >
            <span className="inline-flex items-center gap-1.5">
              <Globe className="h-3.5 w-3.5" /> All spaces
            </span>
            <span className="text-xs text-muted-foreground">broadest scope →</span>
          </button>
          {otherSpaces.length > 0 && (
            <p className="mt-2 text-xs text-muted-foreground">Switch spaces from the space switcher to search elsewhere.</p>
          )}
        </div>
      )}

      {filters.q && (
        <div className="mt-6">
          <Button asChild size="sm">
            <Link href={`/my-tasks?new=${encodeURIComponent(filters.q)}`}>
              <Plus className="mr-1 h-3.5 w-3.5" /> Create a task called &ldquo;{filters.q}&rdquo;
            </Link>
          </Button>
        </div>
      )}
    </div>
  );
};

export default SearchPage;
