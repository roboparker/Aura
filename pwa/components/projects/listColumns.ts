import { displayName } from "@/lib/userDisplay";
import type {
  CustomFieldDefinition,
  CustomFieldKind,
} from "@/components/custom-fields/types";

/**
 * Column model for the project list view: a flat, ordered descriptor list
 * (built-in columns + one per custom-field definition) plus the per-kind
 * sort comparators and filter predicates the column header dropdowns drive.
 * State (sort + filters, and later column order) is persisted per-project,
 * per-browser by `useProjectListView`.
 */

/** A task as the list view sees it — only the bits these helpers read. */
export interface ColumnTask {
  "@id": string;
  title: string;
  dueDate: string | null;
  completedOn: string | null;
  assignees: Array<{
    "@id": string;
    givenName?: string | null;
    familyName?: string | null;
    nickname?: string | null;
    email: string;
  }>;
  tags: Array<{ "@id": string; title: string }>;
  customFieldValues: Array<{ definition: string; value: unknown }>;
}

/** How a column's header dropdown renders its filter, and how rows match it. */
export type FilterKind =
  | "text"
  | "due"
  | "date"
  | "presence"
  | "boolean"
  | "assignees"
  | "tags"
  | "select";

export interface ListColumn {
  /** Stable id: a built-in key, or `cf:<definitionId>` for custom fields. */
  key: string;
  label: string;
  sortable: boolean;
  filter: FilterKind;
  /** Tailwind width class for the shared `<colgroup>`; "" = flex (Task). */
  widthClass: string;
  /** Set for custom-field columns. */
  definition?: CustomFieldDefinition;
}

export const BUILTIN_COLUMN_KEYS = ["task", "due", "assignees", "tags"] as const;

export type SortDir = "asc" | "desc";
export interface SortState {
  key: string;
  dir: SortDir;
}

export type FilterValue =
  | { kind: "text"; query: string }
  | {
      kind: "due";
      mode: "all" | "has" | "none" | "overdue";
      /** Inclusive YYYY-MM-DD bounds, AND-ed with mode. */
      after?: string;
      before?: string;
    }
  | { kind: "date" | "presence"; mode: "all" | "has" | "none" }
  | { kind: "boolean"; mode: "all" | "true" | "false" }
  | { kind: "assignees"; iris: string[]; unassigned: boolean }
  | { kind: "tags"; iris: string[]; untagged: boolean }
  | { kind: "select"; keys: string[]; empty: boolean };

export type FilterMap = Record<string, FilterValue>;

/** The custom-field column id for a definition. */
export const cfColumnKey = (def: CustomFieldDefinition): string =>
  `cf:${def.id}`;

const FILTER_FOR_KIND: Record<CustomFieldKind, FilterKind> = {
  boolean: "boolean",
  text: "text",
  numeric: "presence",
  date: "date",
  select: "select",
  reference: "presence",
};

/** Reference values are IRIs with no stable display order client-side. */
const SORTABLE_KIND: Record<CustomFieldKind, boolean> = {
  boolean: true,
  text: true,
  numeric: true,
  date: true,
  select: true,
  reference: false,
};

/**
 * Build the ordered column list from the project's custom-field
 * definitions. Built-ins lead in their canonical order; custom fields
 * follow in definition order (the caller can re-sequence the result).
 */
export const buildColumns = (
  definitions: CustomFieldDefinition[],
): ListColumn[] => {
  // Fixed, compact widths — including Task — so columns stay tight and the
  // trailing (flex) column absorbs the slack as an empty area on the right.
  const builtins: ListColumn[] = [
    { key: "task", label: "Task", sortable: true, filter: "text", widthClass: "w-72" },
    { key: "due", label: "Due", sortable: true, filter: "due", widthClass: "w-36" },
    { key: "assignees", label: "Assignees", sortable: true, filter: "assignees", widthClass: "w-40" },
    { key: "tags", label: "Tags", sortable: true, filter: "tags", widthClass: "w-36" },
  ];
  // Custom-field columns get a comfortable fixed width — wide enough to fit
  // the header + typical values without truncating (the old w-32 was too
  // tight), but fixed so they don't balloon to fill the row. The trailing
  // flex column still absorbs the leftover as an empty gutter on the right.
  const custom: ListColumn[] = definitions.map((def) => ({
    key: cfColumnKey(def),
    label: def.name,
    sortable: SORTABLE_KIND[def.kind],
    filter: FILTER_FOR_KIND[def.kind],
    widthClass: "w-44",
    definition: def,
  }));
  return [...builtins, ...custom];
};

/**
 * Re-sequence the columns by a saved key order. Keys not in `order` (e.g.
 * a custom field added since the order was saved) fall to the end in their
 * natural order; stale keys in `order` are ignored. So a partial or
 * outdated order degrades gracefully.
 */
export const orderColumns = (
  columns: ListColumn[],
  order: string[],
): ListColumn[] => {
  const byKey = new Map(columns.map((c) => [c.key, c]));
  const seen = new Set<string>();
  const out: ListColumn[] = [];
  for (const key of order) {
    const column = byKey.get(key);
    if (column && !seen.has(key)) {
      out.push(column);
      seen.add(key);
    }
  }
  for (const column of columns) {
    if (!seen.has(column.key)) out.push(column);
  }
  return out;
};

const cfValue = (task: ColumnTask, def: CustomFieldDefinition): unknown =>
  task.customFieldValues.find((v) => v.definition === def["@id"])?.value ?? null;

const optionLabel = (def: CustomFieldDefinition, key: string): string => {
  const opt = def.config.options?.find((o) => o.key === key);
  return (opt?.label ?? key).toLowerCase();
};

/**
 * Extract a comparable scalar for sorting. Returns null for "no value",
 * which the comparator always sorts last regardless of direction.
 */
const sortValue = (task: ColumnTask, column: ListColumn): string | number | null => {
  switch (column.key) {
    case "task":
      return task.title.toLowerCase();
    case "due":
      return task.dueDate ? new Date(task.dueDate).getTime() : null;
    case "assignees":
      return task.assignees[0] ? displayName(task.assignees[0]).toLowerCase() : null;
    case "tags":
      return task.tags[0] ? task.tags[0].title.toLowerCase() : null;
  }

  const def = column.definition;
  if (!def) return null;
  const raw = cfValue(task, def);
  if (raw === null || raw === undefined || raw === "") return null;

  switch (def.kind) {
    case "boolean":
      return raw === true ? 1 : 0;
    case "numeric":
      if (def.subtype === "money" && typeof raw === "object" && raw !== null) {
        const amount = (raw as { amount?: unknown }).amount;
        return typeof amount === "number" ? amount : null;
      }
      return typeof raw === "number" ? raw : null;
    case "date":
      return typeof raw === "string" ? raw : null;
    case "text":
      return typeof raw === "string" ? raw.toLowerCase() : null;
    case "select": {
      const key = Array.isArray(raw) ? raw[0] : raw;
      return typeof key === "string" ? optionLabel(def, key) : null;
    }
    default:
      return null;
  }
};

/** Compare two tasks for the active sort. Nulls always sort last. */
export const compareTasks = (
  a: ColumnTask,
  b: ColumnTask,
  column: ListColumn,
  dir: SortDir,
): number => {
  const av = sortValue(a, column);
  const bv = sortValue(b, column);
  if (av === null && bv === null) return 0;
  if (av === null) return 1;
  if (bv === null) return -1;
  const base =
    typeof av === "number" && typeof bv === "number"
      ? av - bv
      : String(av).localeCompare(String(bv));
  return dir === "asc" ? base : -base;
};

/** Whether a filter value is anything other than its "no filter" default. */
export const isFilterActive = (value: FilterValue | undefined): boolean => {
  if (!value) return false;
  switch (value.kind) {
    case "text":
      return value.query.trim() !== "";
    case "due":
      return value.mode !== "all" || Boolean(value.after) || Boolean(value.before);
    case "date":
    case "presence":
    case "boolean":
      return value.mode !== "all";
    case "assignees":
      return value.iris.length > 0 || value.unassigned;
    case "tags":
      return value.iris.length > 0 || value.untagged;
    case "select":
      return value.keys.length > 0 || value.empty;
  }
};

const isOverdue = (task: ColumnTask): boolean =>
  task.dueDate !== null &&
  task.completedOn === null &&
  new Date(task.dueDate).getTime() < Date.now();

/** Whether a task passes one column's filter. */
const passesFilter = (
  task: ColumnTask,
  column: ListColumn,
  value: FilterValue,
): boolean => {
  switch (value.kind) {
    case "text": {
      const q = value.query.trim().toLowerCase();
      if (q === "") return true;
      if (column.key === "task") return task.title.toLowerCase().includes(q);
      const def = column.definition;
      const raw = def ? cfValue(task, def) : null;
      return typeof raw === "string" && raw.toLowerCase().includes(q);
    }
    case "due": {
      if (value.mode === "has" && task.dueDate === null) return false;
      if (value.mode === "none" && task.dueDate !== null) return false;
      if (value.mode === "overdue" && !isOverdue(task)) return false;
      if (value.after || value.before) {
        if (task.dueDate === null) return false;
        const day = task.dueDate.slice(0, 10);
        if (value.after && day < value.after) return false;
        if (value.before && day > value.before) return false;
      }
      return true;
    }
    case "date":
    case "presence": {
      if (value.mode === "all") return true;
      const def = column.definition;
      const raw = def ? cfValue(task, def) : null;
      const empty =
        raw === null ||
        raw === undefined ||
        raw === "" ||
        (Array.isArray(raw) && raw.length === 0);
      return value.mode === "has" ? !empty : empty;
    }
    case "boolean": {
      if (value.mode === "all") return true;
      const def = column.definition;
      const raw = def ? cfValue(task, def) : null;
      return value.mode === "true" ? raw === true : raw === false;
    }
    case "assignees": {
      const ids = new Set(task.assignees.map((a) => a["@id"]));
      const matchesSelected =
        value.iris.length > 0 && value.iris.some((iri) => ids.has(iri));
      const matchesUnassigned = value.unassigned && ids.size === 0;
      return matchesSelected || matchesUnassigned;
    }
    case "tags": {
      const ids = new Set(task.tags.map((t) => t["@id"]));
      const matchesSelected =
        value.iris.length > 0 && value.iris.some((iri) => ids.has(iri));
      const matchesUntagged = value.untagged && ids.size === 0;
      return matchesSelected || matchesUntagged;
    }
    case "select": {
      const def = column.definition;
      const raw = def ? cfValue(task, def) : null;
      const keys = Array.isArray(raw)
        ? raw.filter((k): k is string => typeof k === "string")
        : typeof raw === "string"
          ? [raw]
          : [];
      const matchesSelected =
        value.keys.length > 0 && value.keys.some((k) => keys.includes(k));
      const matchesEmpty = value.empty && keys.length === 0;
      return matchesSelected || matchesEmpty;
    }
  }
};

/**
 * Apply the active column filters (AND across columns) then the active
 * sort to one section's task list. Pure — returns a new array.
 */
export const applyView = <T extends ColumnTask>(
  tasks: T[],
  columns: ListColumn[],
  sort: SortState | null,
  filters: FilterMap,
): T[] => {
  const active = columns
    .map((c) => ({ column: c, value: filters[c.key] }))
    .filter((e): e is { column: ListColumn; value: FilterValue } =>
      isFilterActive(e.value),
    );

  let result = tasks;
  if (active.length > 0) {
    result = result.filter((task) =>
      active.every(({ column, value }) => passesFilter(task, column, value)),
    );
  }

  if (sort) {
    const column = columns.find((c) => c.key === sort.key);
    if (column?.sortable) {
      result = [...result].sort((a, b) => compareTasks(a, b, column, sort.dir));
    }
  }

  return result;
};
