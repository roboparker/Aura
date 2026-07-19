import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/router";
import {
  AtSign,
  Clock,
  CornerDownLeft,
  Search as SearchIcon,
  Sparkles,
  Trash2,
  X,
} from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import {
  getRecentSearches,
  removeRecentSearch,
  clearRecentSearches,
  type RecentSearch,
} from "@/lib/recentSearches";
import { formatRelative } from "@/lib/relativeTime";
import { cn } from "@/lib/utils";

/**
 * ⌘K command-palette overlay (the first search mockup): a launcher with
 * "Try" suggestion chips, recent searches, and keyboard navigation. It
 * owns the global ⌘K binding and renders itself; the inline navbar
 * SearchBar stays for quick in-place autocomplete. Submitting routes to
 * the dedicated `/search` page.
 */
const Kbd = ({ children }: { children: React.ReactNode }) => (
  <kbd className="rounded border border-input bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
    {children}
  </kbd>
);

const SearchOverlay = () => {
  const router = useRouter();
  const { user, isAuthenticated } = useAuth();
  const { activeSpace } = useActiveSpace();
  const inputRef = useRef<HTMLInputElement>(null);

  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [recents, setRecents] = useState<RecentSearch[]>([]);
  const [activeIndex, setActiveIndex] = useState(0);

  // Global ⌘K / Ctrl+K toggles the overlay.
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      const isMeta = e.metaKey || e.ctrlKey;
      if (isMeta && e.key.toLowerCase() === "k") {
        e.preventDefault();
        if (isAuthenticated) setOpen((v) => !v);
      } else if (e.key === "Escape") {
        setOpen(false);
      }
    };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [isAuthenticated]);

  useEffect(() => {
    if (open) {
      setRecents(getRecentSearches());
      setQuery("");
      setActiveIndex(0);
      // Focus after paint so the autofocus lands inside the portal.
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  const suggestions = useMemo(() => {
    const items: { label: string; icon: typeof Clock; href: string }[] = [
      { label: "Overdue tasks", icon: Clock, href: "/search?kind=tasks&overdue=true" },
    ];
    if (user) {
      items.push({
        label: "@me",
        icon: AtSign,
        href: `/search?kind=tasks&assignee=${encodeURIComponent(`/users/${user.id}`)}`,
      });
    }
    return items;
  }, [user]);

  const go = useCallback(
    (href: string) => {
      setOpen(false);
      void router.push(href);
    },
    [router],
  );

  const runQuery = useCallback(
    (q: string) => {
      const trimmed = q.trim();
      if (!trimmed) return;
      go(`/search?q=${encodeURIComponent(trimmed)}`);
    },
    [go],
  );

  // Flat list of navigable rows for ↑↓ + ↵.
  const rows = useMemo(() => {
    const trimmed = query.trim();
    const out: { key: string; run: () => void }[] = [];
    if (trimmed) {
      out.push({ key: "run", run: () => runQuery(trimmed) });
    } else {
      suggestions.forEach((s) => out.push({ key: `s:${s.label}`, run: () => go(s.href) }));
    }
    recents.forEach((r) => out.push({ key: `r:${r.q}`, run: () => runQuery(r.q) }));
    return out;
  }, [query, suggestions, recents, runQuery, go]);

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setActiveIndex((i) => Math.min(i + 1, rows.length - 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActiveIndex((i) => Math.max(i - 1, 0));
    } else if (e.key === "Enter") {
      e.preventDefault();
      const row = rows[activeIndex];
      if (row) row.run();
      else runQuery(query);
    }
  };

  if (!open || !isAuthenticated) return null;

  const trimmed = query.trim();
  let rowCursor = 0;

  return (
    <div
      className="fixed inset-0 z-[100] flex items-start justify-center bg-black/50 px-4 pt-[12vh]"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) setOpen(false);
      }}
      data-testid="search-overlay"
    >
      <div className="w-full max-w-2xl overflow-hidden rounded-xl border border-border bg-popover shadow-2xl">
        {/* Input */}
        <div className="flex items-center gap-2 border-b border-border px-4 py-3">
          <SearchIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
          <input
            ref={inputRef}
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setActiveIndex(0);
            }}
            onKeyDown={onKeyDown}
            placeholder={activeSpace ? `Search ${activeSpace.name}…` : "Search…"}
            className="flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            data-testid="search-overlay-input"
          />
          {activeSpace && (
            <span className="hidden items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground sm:inline-flex">
              {activeSpace.name}
            </span>
          )}
          <button
            type="button"
            onClick={() => setOpen(false)}
            className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
          >
            <Kbd>esc</Kbd>
          </button>
        </div>

        <div className="max-h-[50vh] overflow-y-auto p-2">
          {/* Run-current row */}
          {trimmed && (
            <Row
              active={activeIndex === rowCursor++}
              onMouseEnter={() => setActiveIndex(0)}
              onClick={() => runQuery(trimmed)}
            >
              <SearchIcon className="h-4 w-4 text-muted-foreground" />
              <span className="min-w-0 flex-1 truncate">
                Search for <span className="font-medium text-foreground">{trimmed}</span>
              </span>
              <CornerDownLeft className="h-3.5 w-3.5 text-muted-foreground" />
            </Row>
          )}

          {/* Suggestion chips (only when empty) */}
          {!trimmed && (
            <div className="px-2 pb-2 pt-1">
              <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Try
              </p>
              <div className="flex flex-wrap gap-1.5">
                {suggestions.map((s) => {
                  const idx = rowCursor++;
                  const Icon = s.icon;
                  return (
                    <button
                      key={s.label}
                      type="button"
                      onClick={() => go(s.href)}
                      onMouseEnter={() => setActiveIndex(idx)}
                      className={cn(
                        "inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs",
                        activeIndex === idx
                          ? "border-primary bg-primary/10 text-foreground"
                          : "border-input text-muted-foreground hover:text-foreground",
                      )}
                    >
                      <Icon className="h-3 w-3" /> {s.label}
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          {/* Recent searches */}
          {recents.length > 0 && (
            <div className="pt-1">
              <div className="flex items-center justify-between px-2 py-1">
                <p className="flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  <Sparkles className="h-3 w-3" /> Recent searches
                </p>
                <button
                  type="button"
                  onClick={() => {
                    clearRecentSearches();
                    setRecents([]);
                  }}
                  className="text-xs text-muted-foreground hover:text-foreground"
                >
                  Clear
                </button>
              </div>
              {recents.map((r) => {
                const idx = rowCursor++;
                return (
                  <Row
                    key={r.q}
                    active={activeIndex === idx}
                    onMouseEnter={() => setActiveIndex(idx)}
                    onClick={() => runQuery(r.q)}
                  >
                    <SearchIcon className="h-3.5 w-3.5 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate font-mono text-xs">{r.q}</span>
                    {typeof r.count === "number" && (
                      <span className="text-xs text-muted-foreground">{r.count} results</span>
                    )}
                    <span className="text-xs text-muted-foreground">{formatRelative(new Date(r.at))}</span>
                    <button
                      type="button"
                      onClick={(e) => {
                        e.stopPropagation();
                        removeRecentSearch(r.q);
                        setRecents(getRecentSearches());
                      }}
                      className="text-muted-foreground hover:text-destructive"
                      aria-label="Remove recent search"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </button>
                  </Row>
                );
              })}
            </div>
          )}

          {!trimmed && recents.length === 0 && (
            <p className="px-3 py-6 text-center text-xs text-muted-foreground">
              Type to search, or pick a suggestion.
            </p>
          )}
        </div>

        {/* Footer hints */}
        <div className="flex items-center gap-3 border-t border-border px-4 py-2 text-xs text-muted-foreground">
          <span className="flex items-center gap-1"><Kbd>↑</Kbd><Kbd>↓</Kbd> navigate</span>
          <span className="flex items-center gap-1"><Kbd>↵</Kbd> open</span>
          <span className="flex items-center gap-1"><Kbd>⌘</Kbd><Kbd>K</Kbd> quick switcher</span>
          <span className="ml-auto flex items-center gap-1">
            <X className="h-3 w-3" /> advanced: <code className="font-mono">tag: @ in: status:</code>
          </span>
        </div>
      </div>
    </div>
  );
};

const Row = ({
  active,
  onClick,
  onMouseEnter,
  children,
}: {
  active: boolean;
  onClick: () => void;
  onMouseEnter: () => void;
  children: React.ReactNode;
}) => (
  <button
    type="button"
    onClick={onClick}
    onMouseEnter={onMouseEnter}
    className={cn(
      "flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm",
      active ? "bg-accent text-accent-foreground" : "hover:bg-accent/50",
    )}
  >
    {children}
  </button>
);

export default SearchOverlay;
