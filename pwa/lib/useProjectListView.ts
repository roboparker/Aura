import { useCallback, useEffect, useState } from "react";
import type {
  FilterMap,
  FilterValue,
  SortState,
} from "@/components/projects/listColumns";

/**
 * Per-project, per-browser persistence for the list view's column sort and
 * filters. Kept in localStorage (a personal view preference, not shared
 * project state) under one key per project. Column order will join this
 * shape when drag-to-reorder lands.
 */
export interface ProjectListView {
  sort: SortState | null;
  filters: FilterMap;
  /** Saved column key order; [] = the natural/default order. */
  order: string[];
}

interface UseProjectListView extends ProjectListView {
  /** Set the active sort, or null to clear it. */
  setSort: (sort: SortState | null) => void;
  setFilter: (key: string, value: FilterValue | null) => void;
  clearAllFilters: () => void;
  setOrder: (order: string[]) => void;
  activeFilterCount: number;
}

const storageKey = (projectId: string): string =>
  `madori.project.${projectId}.listview`;

const empty: ProjectListView = { sort: null, filters: {}, order: [] };

function load(projectId: string): ProjectListView {
  if (typeof window === "undefined") return empty;
  try {
    const raw = window.localStorage.getItem(storageKey(projectId));
    if (!raw) return empty;
    const parsed = JSON.parse(raw) as Partial<ProjectListView>;
    return {
      sort: parsed.sort ?? null,
      filters: parsed.filters ?? {},
      order: Array.isArray(parsed.order) ? parsed.order : [],
    };
  } catch {
    return empty;
  }
}

export function useProjectListView(projectId: string | null): UseProjectListView {
  const [view, setView] = useState<ProjectListView>(empty);

  // Hydrate after mount so SSR and the first paint agree, then load the
  // stored view for this project.
  useEffect(() => {
    if (projectId) setView(load(projectId));
  }, [projectId]);

  const persist = useCallback(
    (next: ProjectListView) => {
      setView(next);
      if (!projectId) return;
      try {
        window.localStorage.setItem(storageKey(projectId), JSON.stringify(next));
      } catch {
        // Storage-disabled browsers: state still applies for the session.
      }
    },
    [projectId],
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

  return {
    sort: view.sort,
    filters: view.filters,
    order: view.order,
    setSort,
    setFilter,
    clearAllFilters,
    setOrder,
    activeFilterCount: Object.keys(view.filters).length,
  };
}
