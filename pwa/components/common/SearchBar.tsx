import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/router";
import { Loader2, Search } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { highlightMatches } from "@/lib/highlightMatches";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

interface SearchResult {
  id: string;
  title: string;
  description: string | null;
  completed: boolean;
}

interface TaskHit {
  "@id": string;
  title: string;
  description: string | null;
  completedOn: string | null;
}

const AUTOCOMPLETE_LIMIT = 6;
const DEBOUNCE_MS = 220;

/**
 * Always-visible search input in the navbar with a live autocomplete
 * dropdown. Submitting (Enter) or "View all results" navigates to the
 * dedicated /search page; clicking a single result jumps there too with
 * the result's IRI as a hash so the /search page can scroll/highlight
 * it in context.
 *
 * The local draft state mirrors the URL while the user types so the
 * field doesn't fight with browser autocomplete; the input commits to
 * the URL on submit, and pre-fills from the URL when the user lands
 * back on /search via a deep link. ⌘/Ctrl+K opens the richer
 * SearchOverlay command palette (mounted in the navbar).
 */
interface SearchBarProps {
  /**
   * Extra classes applied to the outer wrapper. The navbar uses this
   * to make the bar grow into the empty space between the left
   * (wordmark) and right (badges / bell / theme) groups.
   */
  className?: string;
}

const SearchBar = ({ className }: SearchBarProps = {}) => {
  const router = useRouter();
  const inputRef = useRef<HTMLInputElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  // Both /search?q= and the legacy /tasks?search= pre-fill the bar so
  // landing on either deep link feels continuous.
  const initial =
    typeof router.query.q === "string"
      ? router.query.q
      : typeof router.query.search === "string"
        ? router.query.search
        : "";
  const [draft, setDraft] = useState(initial);
  const [open, setOpen] = useState(false);
  const [results, setResults] = useState<SearchResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);

  useEffect(() => {
    setDraft(initial);
  }, [initial]);

  // ⌘/Ctrl+K is owned by SearchOverlay (the command palette) — the
  // inline bar stays for quick type-in-place autocomplete.

  // Close the dropdown on outside click. Listening at capture time so a
  // click into another popover (e.g. the account menu) closes us
  // without a fight.
  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (!containerRef.current?.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [open]);

  // Debounced fetch for the autocomplete list. Cancels in-flight
  // requests on each keystroke so the dropdown can't settle on a stale
  // snapshot.
  const trimmedDraft = draft.trim();
  useEffect(() => {
    if (trimmedDraft.length === 0) {
      setResults([]);
      setLoading(false);
      return;
    }
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setLoading(true);
      try {
        const params = new URLSearchParams({
          search: trimmedDraft,
          itemsPerPage: String(AUTOCOMPLETE_LIMIT),
        });
        const res = await fetch(`${ENTRYPOINT}/tasks?${params}`, {
          credentials: "include",
          signal: controller.signal,
          headers: { Accept: "application/ld+json" },
        });
        if (!res.ok) throw new Error("autocomplete failed");
        const data = await res.json();
        const hits = (data["hydra:member"] ?? data.member ?? []) as TaskHit[];
        setResults(
          hits.map((hit) => ({
            id: hit["@id"],
            title: hit.title,
            description: hit.description,
            completed: Boolean(hit.completedOn),
          })),
        );
        setActiveIndex(-1);
      } catch (err) {
        if ((err as { name?: string })?.name === "AbortError") return;
        setResults([]);
      } finally {
        setLoading(false);
      }
    }, DEBOUNCE_MS);
    return () => {
      controller.abort();
      window.clearTimeout(timer);
    };
  }, [trimmedDraft]);

  const navigate = (target: string) => {
    setOpen(false);
    void router.push(target);
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (activeIndex >= 0 && results[activeIndex]) {
      goToResult(results[activeIndex]);
      return;
    }
    if (trimmedDraft.length === 0) {
      navigate("/search");
      return;
    }
    navigate(`/search?q=${encodeURIComponent(trimmedDraft)}`);
  };

  const goToResult = (result: SearchResult) => {
    // Hash carries the IRI so the /search page can scroll the matched
    // row into view rather than dropping the user at the page top.
    navigate(
      `/search?q=${encodeURIComponent(trimmedDraft)}#${encodeURIComponent(result.id)}`,
    );
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (!open || results.length === 0) return;
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setActiveIndex((i) => (i + 1) % results.length);
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActiveIndex((i) => (i <= 0 ? results.length - 1 : i - 1));
    } else if (e.key === "Escape") {
      setOpen(false);
    }
  };

  const showDropdown = open && trimmedDraft.length > 0;

  return (
    <div
      ref={containerRef}
      className={cn("relative hidden sm:block", className)}
    >
      <form onSubmit={submit} role="search" aria-label="Search tasks">
        <div className="relative">
          <Search
            className="absolute left-2 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"
            aria-hidden="true"
          />
          <Input
            ref={inputRef}
            type="search"
            value={draft}
            onChange={(e) => {
              setDraft(e.target.value);
              setOpen(true);
            }}
            onFocus={() => setOpen(true)}
            onKeyDown={onKeyDown}
            placeholder="Search tasks…"
            className="h-8 w-full pl-8"
            data-testid="navbar-search"
            aria-label="Search tasks"
            aria-autocomplete="list"
            aria-expanded={showDropdown}
            aria-controls="search-autocomplete"
          />
        </div>
      </form>

      {showDropdown && (
        <SearchAutocomplete
          query={trimmedDraft}
          results={results}
          loading={loading}
          activeIndex={activeIndex}
          onSelect={goToResult}
          onViewAll={() =>
            navigate(`/search?q=${encodeURIComponent(trimmedDraft)}`)
          }
        />
      )}
    </div>
  );
};

interface AutocompleteProps {
  query: string;
  results: SearchResult[];
  loading: boolean;
  activeIndex: number;
  onSelect: (result: SearchResult) => void;
  onViewAll: () => void;
}

const SearchAutocomplete = ({
  query,
  results,
  loading,
  activeIndex,
  onSelect,
  onViewAll,
}: AutocompleteProps) => (
  <div
    id="search-autocomplete"
    role="listbox"
    className="absolute left-0 right-0 top-full mt-1 min-w-[20rem] rounded-md border bg-popover text-popover-foreground shadow-md z-50"
    data-testid="search-autocomplete"
  >
    {loading && results.length === 0 ? (
      <div className="flex items-center gap-2 p-3 text-sm text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" />
        Searching…
      </div>
    ) : results.length === 0 ? (
      <div className="p-3 text-sm text-muted-foreground">
        No matches for &ldquo;{query}&rdquo;.
      </div>
    ) : (
      <ul className="max-h-80 overflow-y-auto py-1">
        {results.map((result, i) => (
          <li key={result.id}>
            <button
              type="button"
              role="option"
              aria-selected={i === activeIndex}
              onClick={() => onSelect(result)}
              data-testid="search-autocomplete-item"
              className={cn(
                "flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left text-sm",
                i === activeIndex
                  ? "bg-accent text-accent-foreground"
                  : "hover:bg-accent/50",
              )}
            >
              <span
                className={cn(
                  "font-medium line-clamp-1",
                  result.completed && "line-through text-muted-foreground",
                )}
              >
                {highlightMatches(result.title, query)}
              </span>
              {result.description && (
                <span className="text-xs text-muted-foreground line-clamp-1">
                  {highlightMatches(snippetOf(result.description), query)}
                </span>
              )}
            </button>
          </li>
        ))}
      </ul>
    )}
    <div className="border-t p-1">
      <button
        type="button"
        onClick={onViewAll}
        className="block w-full rounded-sm px-3 py-1.5 text-left text-xs text-muted-foreground hover:bg-accent hover:text-accent-foreground"
        data-testid="search-autocomplete-view-all"
      >
        View all results for &ldquo;{query}&rdquo;
      </button>
    </div>
  </div>
);

const SNIPPET_MAX = 120;

const snippetOf = (description: string): string =>
  description.length > SNIPPET_MAX
    ? `${description.slice(0, SNIPPET_MAX).trimEnd()}…`
    : description;

export default SearchBar;
