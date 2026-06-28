import {
  FormEvent,
  type ReactNode,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import Link from "next/link";
import { useQueryClient } from "@tanstack/react-query";
import {
  Lock,
  MessageSquare,
  MoreHorizontal,
  Pin,
  Search,
  Sparkles,
  Megaphone,
  HelpCircle,
  MessageCircle,
  Trash2,
} from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { trackEvent } from "@/lib/analytics";
import { displayName } from "@/lib/userDisplay";
import { formatRelative } from "@/lib/relativeTime";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import AvatarStack from "@/components/user/AvatarStack";
import PageHeader from "@/components/common/PageHeader";
import CategoryBadge, {
  CATEGORY_LABEL,
  CATEGORY_META,
  CATEGORY_ORDER,
  type DiscussionCategory,
} from "@/components/discussions/CategoryBadge";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";

export { CATEGORY_LABEL, type DiscussionCategory };

const FILTERS: { value: DiscussionCategory | "all"; label: string }[] = [
  { value: "all", label: "All" },
  ...CATEGORY_ORDER.map((c) => ({ value: c, label: CATEGORY_LABEL[c] })),
];
export { formatRelative };

export type Participant = AvatarUser & { "@id"?: string; id: string };

export interface Discussion {
  "@id": string;
  id: string;
  title: string;
  body: string;
  category: DiscussionCategory;
  isPinned: boolean;
  isLocked: boolean;
  createdAt: string;
  updatedAt: string | null;
  author: AvatarUser & { "@id": string; id: string };
  // Read-time aggregates from DiscussionAggregateProvider.
  commentCount: number;
  participantCount: number;
  participants: Participant[];
  lastActivityAt: string;
}

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

interface Props {
  spaceIri: string;
  currentUserIri: string | null;
  isSpaceAdmin: boolean;
  /** Notified with the total thread count after each load (header chip). */
  onCountChange?: (count: number) => void;
  /** When set, the panel renders a {@link PageHeader} (title + icon +
   *  description + count + "New discussion" action + search) instead of
   *  the compact inline toolbar. */
  title?: ReactNode;
  description?: ReactNode;
  icon?: ReactNode;
}

/** Stable, human-ish handle for a thread — there's no server slug. */
const slugFor = (title: string): string => {
  const word = title
    .toLowerCase()
    .replace(/[^a-z0-9\s]/g, " ")
    .trim()
    .split(/\s+/)[0];
  return word ? `d-${word.slice(0, 14)}` : "d-thread";
};

export const errorMessage = async (res: Response): Promise<string> => {
  const data = await res.json().catch(() => ({}));
  return (
    data.detail ||
    data.description ||
    data["hydra:description"] ||
    "Request failed."
  );
};

const sortDiscussions = (list: Discussion[]): Discussion[] =>
  [...list].sort((a, b) => {
    if (a.isPinned !== b.isPinned) return a.isPinned ? -1 : 1;
    return new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime();
  });

const DiscussionsPanel = ({
  spaceIri,
  currentUserIri,
  isSpaceAdmin,
  onCountChange,
  title,
  description,
  icon,
}: Props) => {
  const queryClient = useQueryClient();
  const [discussions, setDiscussions] = useState<Discussion[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [filter, setFilter] = useState<DiscussionCategory | "all">("all");
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const searchRef = useRef<HTMLInputElement>(null);

  // Composer
  const [showComposer, setShowComposer] = useState(false);
  const [newTitle, setNewTitle] = useState("");
  const [newBody, setNewBody] = useState("");
  const [newCategory, setNewCategory] = useState<DiscussionCategory>("general");
  const [bodyKey, setBodyKey] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [composerError, setComposerError] = useState<string | null>(null);

  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  // "/" focuses search, the convention the mockups hint at.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (
        e.key === "/" &&
        !e.metaKey &&
        !e.ctrlKey &&
        document.activeElement?.tagName !== "INPUT" &&
        document.activeElement?.tagName !== "TEXTAREA" &&
        !(document.activeElement as HTMLElement | null)?.isContentEditable
      ) {
        e.preventDefault();
        searchRef.current?.focus();
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      const params = new URLSearchParams({ space: spaceIri });
      if (debouncedSearch) params.set("search", debouncedSearch);
      const res = await fetch(`${ENTRYPOINT}/discussions?${params.toString()}`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) throw new Error("Failed to load discussions.");
      const data: Collection<Discussion> = await res.json();
      const list = data.member ?? data["hydra:member"] ?? [];
      setDiscussions(list);
      onCountChange?.(list.length);
    } catch (err) {
      setLoadError(
        err instanceof Error ? err.message : "Failed to load discussions.",
      );
    } finally {
      setIsLoading(false);
    }
  }, [spaceIri, debouncedSearch, onCountChange]);

  useEffect(() => {
    void load();
  }, [load]);

  const filtered = useMemo(
    () =>
      filter === "all"
        ? discussions
        : discussions.filter((d) => d.category === filter),
    [discussions, filter],
  );

  const pinned = useMemo(() => filtered.filter((d) => d.isPinned), [filtered]);
  const rest = useMemo(() => filtered.filter((d) => !d.isPinned), [filtered]);

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const title = newTitle.trim();
    const body = newBody.trim();
    if (!title || !body) return;

    setSubmitting(true);
    setComposerError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/discussions`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({ space: spaceIri, title, body, category: newCategory }),
      });
      if (!res.ok) throw new Error(await errorMessage(res));
      const created: Discussion = await res.json();
      trackEvent("discussion-create", { category: newCategory });
      setDiscussions((prev) => sortDiscussions([created, ...prev]));
      // Refresh the sidebar nav list (shares the ["discussions"] key prefix).
      void queryClient.invalidateQueries({ queryKey: ["discussions"] });
      setNewTitle("");
      setNewBody("");
      setNewCategory("general");
      setBodyKey((k) => k + 1);
      setShowComposer(false);
    } catch (err) {
      setComposerError(
        err instanceof Error ? err.message : "Failed to start discussion.",
      );
    } finally {
      setSubmitting(false);
    }
  };

  const patchDiscussion = useCallback(
    async (
      discussion: Discussion,
      patch: Partial<Pick<Discussion, "isPinned" | "isLocked">>,
    ): Promise<void> => {
      const res = await fetch(`${ENTRYPOINT}${discussion["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: {
          "Content-Type": "application/merge-patch+json",
          Accept: "application/ld+json",
        },
        body: JSON.stringify(patch),
      });
      if (!res.ok) throw new Error(await errorMessage(res));
      const updated: Discussion = await res.json();
      setDiscussions((prev) =>
        sortDiscussions(
          prev.map((d) => (d["@id"] === updated["@id"] ? { ...d, ...updated } : d)),
        ),
      );
    },
    [],
  );

  const deleteDiscussion = useCallback(async (discussion: Discussion) => {
    const res = await fetch(`${ENTRYPOINT}${discussion["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) throw new Error(await errorMessage(res));
    setDiscussions((prev) => prev.filter((d) => d["@id"] !== discussion["@id"]));
  }, []);

  const hasAny = discussions.length > 0;

  const searchBox = (
    <div className="relative w-full sm:flex-1">
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
      <Input
        ref={searchRef}
        type="search"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        placeholder="Search discussions…"
        className="pl-9"
        data-testid="discussion-search"
      />
    </div>
  );

  const newButton = (
    <Button
      type="button"
      size="sm"
      onClick={() => setShowComposer((v) => !v)}
      data-testid="discussion-toggle-composer"
      className="shrink-0"
    >
      {showComposer ? "Cancel" : "New discussion"}
    </Button>
  );

  // Category filters — one joined segmented control.
  const filters = (
    <div
      className="inline-flex w-fit overflow-hidden rounded-full border border-input text-xs font-medium"
      role="tablist"
      aria-label="Filter by category"
    >
      {FILTERS.map((c, i) => {
        const active = filter === c.value;
        const dot = c.value === "all" ? null : CATEGORY_META[c.value].dot;
        return (
          <button
            key={c.value}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => setFilter(c.value)}
            className={cn(
              "inline-flex items-center gap-1.5 px-3 py-1 transition-colors",
              i > 0 && "border-l border-input",
              active
                ? "bg-primary text-primary-foreground"
                : "bg-background text-muted-foreground hover:text-foreground",
            )}
          >
            {dot && (
              <span className={cn("h-1.5 w-1.5 rounded-full", dot)} aria-hidden />
            )}
            {c.label}
          </button>
        );
      })}
    </div>
  );

  return (
    <div className="space-y-4" data-testid="discussions-panel">
      {title ? (
        <PageHeader
          title={title}
          icon={icon}
          subtitle={description}
          count={discussions.length}
          actions={newButton}
        >
          {searchBox}
        </PageHeader>
      ) : (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          {searchBox}
          {newButton}
        </div>
      )}

      {filters}

      {showComposer && (
        <Card data-testid="discussion-composer">
          <CardContent className="pt-6">
            <form onSubmit={handleCreate} className="space-y-3">
              <div className="space-y-1.5">
                <Label htmlFor="discussion-title">Title</Label>
                <Input
                  id="discussion-title"
                  type="text"
                  value={newTitle}
                  onChange={(e) => setNewTitle(e.target.value)}
                  required
                  maxLength={200}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="discussion-category">Category</Label>
                <select
                  id="discussion-category"
                  value={newCategory}
                  onChange={(e) =>
                    setNewCategory(e.target.value as DiscussionCategory)
                  }
                  className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  {CATEGORY_ORDER.map((cat) => (
                    <option key={cat} value={cat}>
                      {CATEGORY_LABEL[cat]}
                    </option>
                  ))}
                </select>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="discussion-body">Body</Label>
                <MarkdownEditor
                  key={bodyKey}
                  id="discussion-body"
                  ariaLabel="Discussion body"
                  value={newBody}
                  onChange={setNewBody}
                />
              </div>
              {composerError && (
                <Alert variant="destructive">
                  <AlertDescription>{composerError}</AlertDescription>
                </Alert>
              )}
              <Button
                type="submit"
                disabled={submitting || !newTitle.trim() || !newBody.trim()}
                data-testid="discussion-submit"
              >
                {submitting ? "Posting…" : "Post"}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}

      {loadError && (
        <Alert variant="destructive">
          <AlertDescription>{loadError}</AlertDescription>
        </Alert>
      )}

      {isLoading ? (
        <p className="text-muted-foreground text-sm">Loading discussions…</p>
      ) : !hasAny && !debouncedSearch ? (
        <EmptyState onStart={() => setShowComposer(true)} />
      ) : filtered.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center">
            <p className="text-muted-foreground text-sm">
              {debouncedSearch
                ? `No discussions match “${debouncedSearch}”.`
                : "No discussions in this category yet."}
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-6" data-testid="discussion-list">
          {filter === "all" ? (
            <>
              {pinned.length > 0 && (
                <Section
                  icon={<Pin className="h-3.5 w-3.5" />}
                  label="Pinned"
                  count={pinned.length}
                  rows={pinned}
                  currentUserIri={currentUserIri}
                  isSpaceAdmin={isSpaceAdmin}
                  onPatch={patchDiscussion}
                  onDelete={deleteDiscussion}
                />
              )}
              <Section
                label="All discussions"
                count={rest.length}
                rows={rest}
                currentUserIri={currentUserIri}
                isSpaceAdmin={isSpaceAdmin}
                onPatch={patchDiscussion}
                onDelete={deleteDiscussion}
              />
            </>
          ) : (
            <Section
              label={CATEGORY_LABEL[filter]}
              count={filtered.length}
              rows={filtered}
              currentUserIri={currentUserIri}
              isSpaceAdmin={isSpaceAdmin}
              onPatch={patchDiscussion}
              onDelete={deleteDiscussion}
            />
          )}
        </div>
      )}
    </div>
  );
};

interface SectionProps {
  icon?: React.ReactNode;
  label: string;
  count: number;
  rows: Discussion[];
  currentUserIri: string | null;
  isSpaceAdmin: boolean;
  onPatch: (
    d: Discussion,
    patch: Partial<Pick<Discussion, "isPinned" | "isLocked">>,
  ) => Promise<void>;
  onDelete: (d: Discussion) => Promise<void>;
}

const Section = ({
  icon,
  label,
  count,
  rows,
  currentUserIri,
  isSpaceAdmin,
  onPatch,
  onDelete,
}: SectionProps) => (
  <section>
    <div className="mb-2 flex items-center gap-2 px-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
      {icon}
      <span>{label}</span>
      <span className="text-muted-foreground/70">{count}</span>
    </div>
    <div className="overflow-hidden rounded-lg border border-border">
      {/* Column header — desktop only */}
      <div className="hidden grid-cols-[minmax(0,1fr)_150px_170px_90px_120px_40px] gap-3 border-b border-border bg-muted/40 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground md:grid">
        <span>Thread</span>
        <span>Category</span>
        <span>Author</span>
        <span>Replies</span>
        <span>Last activity</span>
        <span className="sr-only">Actions</span>
      </div>
      <ul className="divide-y divide-border">
        {rows.map((d) => (
          <DiscussionRow
            key={d["@id"]}
            discussion={d}
            currentUserIri={currentUserIri}
            isSpaceAdmin={isSpaceAdmin}
            onPatch={onPatch}
            onDelete={onDelete}
          />
        ))}
      </ul>
    </div>
  </section>
);

interface RowProps {
  discussion: Discussion;
  currentUserIri: string | null;
  isSpaceAdmin: boolean;
  onPatch: (
    d: Discussion,
    patch: Partial<Pick<Discussion, "isPinned" | "isLocked">>,
  ) => Promise<void>;
  onDelete: (d: Discussion) => Promise<void>;
}

const DiscussionRow = ({
  discussion,
  currentUserIri,
  isSpaceAdmin,
  onPatch,
  onDelete,
}: RowProps) => {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isAuthor = currentUserIri === discussion.author["@id"];
  const canDelete = isAuthor || isSpaceAdmin;
  const canModerate = isSpaceAdmin;
  const hasMenu = canModerate || canDelete;

  const run = async (fn: () => Promise<void>, fail: string) => {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (err) {
      setError(err instanceof Error ? err.message : fail);
    } finally {
      setBusy(false);
    }
  };

  const remove = () => {
    if (!window.confirm(`Delete discussion "${discussion.title}"?`)) return;
    void run(() => onDelete(discussion), "Failed to delete.");
  };

  return (
    <li data-testid="discussion-item" className="group">
      <div className="grid grid-cols-[minmax(0,1fr)_40px] items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40 md:grid-cols-[minmax(0,1fr)_150px_170px_90px_120px_40px]">
        {/* Thread */}
        <div className="min-w-0">
          <div className="flex items-center gap-1.5">
            {discussion.isPinned && (
              <Pin className="h-3.5 w-3.5 shrink-0 text-amber-500" aria-label="Pinned" />
            )}
            <Link
              href={`/discussions/${discussion.id}`}
              className="truncate font-medium text-foreground no-underline hover:underline"
              data-testid="discussion-title"
            >
              {discussion.title}
            </Link>
            {discussion.isLocked && (
              <Lock
                className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                aria-label="Locked"
              />
            )}
          </div>
          <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
            <span className="font-mono text-xs text-muted-foreground/80">
              {slugFor(discussion.title)}
            </span>
            {discussion.participants.length > 0 && (
              <>
                <span aria-hidden>·</span>
                <AvatarStack
                  users={discussion.participants}
                  total={discussion.participantCount}
                />
                <span>
                  {discussion.participantCount}{" "}
                  {discussion.participantCount === 1 ? "participant" : "participants"}
                </span>
              </>
            )}
          </div>
          {/* Mobile-only meta */}
          <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground md:hidden">
            <CategoryBadge category={discussion.category} />
            <span className="inline-flex items-center gap-1">
              <MessageSquare className="h-3.5 w-3.5" />
              {discussion.commentCount}
            </span>
            <span>{formatRelative(discussion.lastActivityAt)}</span>
          </div>
          {error && (
            <p role="alert" className="mt-1 text-xs text-destructive" data-testid="discussion-error">
              {error}
            </p>
          )}
        </div>

        {/* Category */}
        <div className="hidden md:block">
          <CategoryBadge category={discussion.category} />
        </div>

        {/* Author */}
        <div className="hidden min-w-0 items-center gap-2 md:flex">
          <UserAvatar user={discussion.author} size="sm" />
          <span className="truncate text-sm text-foreground">
            {displayName(discussion.author)}
          </span>
          {isAuthor && (
            <span className="rounded bg-muted px-1 text-xs font-medium uppercase text-muted-foreground">
              you
            </span>
          )}
        </div>

        {/* Replies */}
        <div className="hidden items-center gap-1.5 text-sm text-muted-foreground md:flex">
          <MessageSquare className="h-4 w-4" />
          {discussion.commentCount}
        </div>

        {/* Last activity */}
        <div className="hidden text-sm text-muted-foreground md:block">
          {formatRelative(discussion.lastActivityAt)}
        </div>

        {/* Overflow menu */}
        <div className="flex justify-end">
          {hasMenu && (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  className="h-7 w-7 text-muted-foreground"
                  disabled={busy}
                  data-testid="discussion-row-menu"
                  aria-label="Discussion actions"
                >
                  <MoreHorizontal className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {canModerate && (
                  <>
                    <DropdownMenuItem
                      onClick={() =>
                        void run(
                          () => onPatch(discussion, { isPinned: !discussion.isPinned }),
                          "Failed to update.",
                        )
                      }
                      data-testid="discussion-toggle-pin"
                    >
                      <Pin className="mr-2 h-3.5 w-3.5" />
                      {discussion.isPinned ? "Unpin" : "Pin"}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      onClick={() =>
                        void run(
                          () => onPatch(discussion, { isLocked: !discussion.isLocked }),
                          "Failed to update.",
                        )
                      }
                      data-testid="discussion-toggle-lock"
                    >
                      <Lock className="mr-2 h-3.5 w-3.5" />
                      {discussion.isLocked ? "Unlock" : "Lock"}
                    </DropdownMenuItem>
                    {canDelete && <DropdownMenuSeparator />}
                  </>
                )}
                {canDelete && (
                  <DropdownMenuItem
                    onClick={remove}
                    className="text-destructive focus:text-destructive"
                    data-testid="discussion-delete"
                  >
                    <Trash2 className="mr-2 h-3.5 w-3.5" />
                    Delete
                  </DropdownMenuItem>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        </div>
      </div>
    </li>
  );
};

const LEGEND: {
  category: DiscussionCategory;
  icon: React.ReactNode;
  blurb: string;
}[] = [
  { category: "general", icon: <MessageCircle className="h-4 w-4" />, blurb: "Anything that doesn't fit another category." },
  { category: "ideas", icon: <Sparkles className="h-4 w-4" />, blurb: "Pitch, refine, get feedback before committing." },
  { category: "announcements", icon: <Megaphone className="h-4 w-4" />, blurb: "Broadcast-style. Often pinned, sometimes locked." },
  { category: "q-and-a", icon: <HelpCircle className="h-4 w-4" />, blurb: "Open questions for the space to weigh in on." },
];

const EmptyState = ({ onStart }: { onStart: () => void }) => (
  <Card data-testid="discussions-empty">
    <CardContent className="flex flex-col items-center px-6 py-12 text-center">
      <div className="flex items-center gap-3">
        {LEGEND.map(({ category, icon }) => (
          <span
            key={category}
            className={cn(
              "inline-flex h-11 w-11 items-center justify-center rounded-xl border",
              CATEGORY_META[category].pill,
            )}
          >
            <span className={CATEGORY_META[category].icon}>{icon}</span>
          </span>
        ))}
      </div>
      <h3 className="mt-5 text-lg font-semibold">No discussions in this space yet</h3>
      <p className="mt-2 max-w-md text-sm text-muted-foreground">
        Discussions are <span className="font-medium text-foreground">space-level threads</span> for
        anything that doesn&apos;t live inside a project — announcements, open questions,
        half-formed ideas worth a second pair of eyes.
      </p>
      <div className="mt-5">
        <Button type="button" onClick={onStart} data-testid="discussion-empty-start">
          <MessageSquare className="mr-2 h-4 w-4" /> Start the first discussion
        </Button>
      </div>
      <div className="mt-8 grid w-full max-w-2xl grid-cols-2 gap-4 border-t border-border pt-6 text-left sm:grid-cols-4">
        {LEGEND.map(({ category, blurb }) => (
          <div key={category} className="space-y-1">
            <CategoryBadge category={category} />
            <p className="text-xs text-muted-foreground">{blurb}</p>
          </div>
        ))}
      </div>
    </CardContent>
  </Card>
);

export default DiscussionsPanel;
