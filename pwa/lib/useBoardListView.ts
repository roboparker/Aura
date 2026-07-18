import { useCallback, useEffect, useState } from "react";
import type {
  FilterMap,
  FilterValue,
  SortState,
} from "@/components/boards/listColumns";

/**
 * Per-board, per-browser persistence for the list view's column sort and
 * filters. Kept in localStorage (a personal view preference, not shared
 * board state) under one key per board. Column order will join this
 * shape when drag-to-reorder lands.
 */
export interface BoardListView {
  sort: SortState | null;
  filters: FilterMap;
  /** Saved column key order; [] = the natural/default order. */
  order: string[];
  /** Saved section group-key order (incl. the default group); [] = natural. */
  sectionOrder: string[];
}

interface UseBoardListView extends BoardListView {
  /** Set the active sort, or null to clear it. */
  setSort: (sort: SortState | null) => void;
  setFilter: (key: string, value: FilterValue | null) => void;
  clearAllFilters: () => void;
  setOrder: (order: string[]) => void;
  setSectionOrder: (order: string[]) => void;
  activeFilterCount: number;
}

const storageKey = (boardId: string): string =>
  `madori.board.${boardId}.listview`;

const empty: BoardListView = {
  sort: null,
  filters: {},
  order: [],
  sectionOrder: [],
};

function load(boardId: string): BoardListView {
  if (typeof window === "undefined") return empty;
  try {
    const raw = window.localStorage.getItem(storageKey(boardId));
    if (!raw) return empty;
    const parsed = JSON.parse(raw) as Partial<BoardListView>;
    return {
      sort: parsed.sort ?? null,
      filters: parsed.filters ?? {},
      order: Array.isArray(parsed.order) ? parsed.order : [],
      sectionOrder: Array.isArray(parsed.sectionOrder) ? parsed.sectionOrder : [],
    };
  } catch {
    return empty;
  }
}

export function useBoardListView(boardId: string | null): UseBoardListView {
  const [view, setView] = useState<BoardListView>(empty);

  // Hydrate after mount so SSR and the first paint agree, then load the
  // stored view for this board.
  useEffect(() => {
    if (boardId) setView(load(boardId));
  }, [boardId]);

  const persist = useCallback(
    (next: BoardListView) => {
      setView(next);
      if (!boardId) return;
      try {
        window.localStorage.setItem(storageKey(boardId), JSON.stringify(next));
      } catch {
        // Storage-disabled browsers: state still applies for the session.
      }
    },
    [boardId],
  );

  const setSort = useCallback(
    (sort: SortState | null) => persist({ ...view, sort }),
    [persist, view],
  );

  const setFilter = useCallback(
    (key: string, value: FilterValue | null) => {
      const filters = { ...view.filters };
      if (value === null) {
        delete filters[key];
      } else {
        filters[key] = value;
      }
      persist({ ...view, filters });
    },
    [persist, view],
  );

  const clearAllFilters = useCallback(
    () => persist({ ...view, filters: {} }),
    [persist, view],
  );

  const setOrder = useCallback(
    (order: string[]) => persist({ ...view, order }),
    [persist, view],
  );

  const setSectionOrder = useCallback(
    (sectionOrder: string[]) => persist({ ...view, sectionOrder }),
    [persist, view],
  );

  return {
    sort: view.sort,
    filters: view.filters,
    order: view.order,
    sectionOrder: view.sectionOrder,
    setSort,
    setFilter,
    clearAllFilters,
    setOrder,
    setSectionOrder,
    activeFilterCount: Object.keys(view.filters).length,
  };
}
