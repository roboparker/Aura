// Recent search history, persisted in localStorage so the search overlay
// can offer one-tap re-runs. Capped and deduped; newest first.

export interface RecentSearch {
  q: string;
  /** epoch ms of the last run. */
  at: number;
  /** result count from the last run, when known. */
  count?: number;
}

const KEY = "aura.recentSearches";
const CAP = 6;

const read = (): RecentSearch[] => {
  if (typeof window === "undefined") return [];
  try {
    const raw = window.localStorage.getItem(KEY);
    if (!raw) return [];
    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter(
      (r): r is RecentSearch =>
        typeof r === "object" &&
        r !== null &&
        typeof (r as RecentSearch).q === "string" &&
        typeof (r as RecentSearch).at === "number",
    );
  } catch {
    return [];
  }
};

const write = (list: RecentSearch[]): void => {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(KEY, JSON.stringify(list.slice(0, CAP)));
  } catch {
    // Quota / disabled storage — recents are a convenience, drop silently.
  }
};

export const getRecentSearches = (): RecentSearch[] => read();

/** Records (or refreshes) a query at the top of the list, deduped by text. */
export const addRecentSearch = (q: string, count?: number): void => {
  const trimmed = q.trim();
  if (!trimmed) return;
  const rest = read().filter((r) => r.q !== trimmed);
  write([{ q: trimmed, at: Date.now(), count }, ...rest]);
};

export const removeRecentSearch = (q: string): void => {
  write(read().filter((r) => r.q !== q));
};

export const clearRecentSearches = (): void => write([]);
