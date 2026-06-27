import { useState, type HTMLAttributes } from "react";
import {
  ArrowDown,
  ArrowUp,
  ChevronsUpDown,
  Filter,
  Pencil,
} from "lucide-react";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Input } from "@/components/ui/input";
import { Calendar } from "@/components/ui/calendar";
import { Checkbox } from "@/components/ui/checkbox";
import { displayName } from "@/lib/userDisplay";
import { cn } from "@/lib/utils";
import type { AssigneeOption } from "@/components/tasks/AssigneesCombobox";
import type { TagOption } from "@/components/tasks/TagsCombobox";
import {
  isFilterActive,
  type FilterValue,
  type ListColumn,
  type SortState,
} from "./listColumns";

interface ColumnHeaderMenuProps {
  column: ListColumn;
  sort: SortState | null;
  filter: FilterValue | undefined;
  onSetSort: (sort: SortState | null) => void;
  onSetFilter: (key: string, value: FilterValue | null) => void;
  assignableUsers: AssigneeOption[];
  allTags: TagOption[];
  /** When set (custom-field columns), the menu shows an "Edit field" action. */
  onEdit?: () => void;
  /** Drag listeners/attributes applied to the label area (the drag handle). */
  dragHandleProps?: HTMLAttributes<HTMLDivElement>;
}

// Local date <-> YYYY-MM-DD helpers (no timezone shift; date-only filter bounds).
const toDay = (d: Date) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(
    d.getDate(),
  ).padStart(2, "0")}`;
const fromDay = (s: string) => {
  const [y, m, d] = s.split("-").map(Number);
  return new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1);
};
const formatDay = (s: string) =>
  fromDay(s).toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
  });

/** A labelled row whose value text opens a calendar date picker on click. */
const DateBound = ({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string;
  onChange: (next: string) => void;
}) => {
  const [open, setOpen] = useState(false);
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          className="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1 text-left text-sm hover:bg-accent"
        >
          <span className="text-muted-foreground">{label}</span>
          <span className={value ? "font-medium" : "text-muted-foreground/60"}>
            {value ? formatDay(value) : "Any date"}
          </span>
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-auto p-0">
        <Calendar
          mode="single"
          selected={value ? fromDay(value) : undefined}
          onSelect={(d) => {
            onChange(d ? toDay(d) : "");
            setOpen(false);
          }}
          autoFocus
        />
        {value && (
          <button
            type="button"
            onClick={() => {
              onChange("");
              setOpen(false);
            }}
            className="w-full border-t px-2 py-1.5 text-left text-sm text-muted-foreground hover:bg-accent"
          >
            Clear
          </button>
        )}
      </PopoverContent>
    </Popover>
  );
};

/** A radio-style choice button used by the single-select filter kinds. */
const Choice = ({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) => (
  <button
    type="button"
    onClick={onClick}
    className={cn(
      "rounded-md px-2 py-1 text-left text-sm transition-colors",
      active ? "bg-accent text-accent-foreground font-medium" : "hover:bg-accent",
    )}
  >
    {children}
  </button>
);

/** A checkbox row used by the multi-select filter kinds. */
const CheckRow = ({
  checked,
  onChange,
  children,
}: {
  checked: boolean;
  onChange: (next: boolean) => void;
  children: React.ReactNode;
}) => (
  <label className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-sm hover:bg-accent">
    <Checkbox
      checked={checked}
      onCheckedChange={(v) => onChange(v === true)}
      className="size-4"
    />
    <span className="min-w-0 flex-1 truncate">{children}</span>
  </label>
);

const ColumnHeaderMenu = ({
  column,
  sort,
  filter,
  onSetSort,
  onSetFilter,
  assignableUsers,
  allTags,
  onEdit,
  dragHandleProps,
}: ColumnHeaderMenuProps) => {
  const [open, setOpen] = useState(false);
  const sortedHere = sort?.key === column.key;
  const filtered = isFilterActive(filter);

  const set = (value: FilterValue | null) => onSetFilter(column.key, value);

  // Cycle the sort on click: unsorted → ascending → descending → unsorted.
  const cycleSort = () => {
    if (!sortedHere) onSetSort({ key: column.key, dir: "asc" });
    else if (sort.dir === "asc") onSetSort({ key: column.key, dir: "desc" });
    else onSetSort(null);
  };

  return (
    <div className="flex w-full items-center gap-1">
      {/* The whole label area is the drag handle — click anywhere to reorder. */}
      <div
        className={cn(
          "flex min-w-0 flex-1 cursor-grab touch-none items-center gap-1 select-none",
          (sortedHere || filtered) && "text-foreground",
        )}
        aria-label={`Reorder ${column.label} column`}
        {...dragHandleProps}
      >
        <span className="truncate">{column.label}</span>
      </div>
      {/* Click to cycle the sort direction without opening the menu. */}
      {column.sortable && (
        <button
          type="button"
          onClick={cycleSort}
          className={cn(
            "shrink-0 rounded p-0.5 hover:bg-accent hover:text-foreground",
            sortedHere ? "text-foreground" : "text-muted-foreground",
          )}
          aria-label={`Sort by ${column.label}`}
          data-testid={`column-sort-${column.key}`}
        >
          {sortedHere ? (
            sort.dir === "asc" ? (
              <ArrowUp className="size-3.5" />
            ) : (
              <ArrowDown className="size-3.5" />
            )
          ) : (
            <ChevronsUpDown className="size-3.5 opacity-40" />
          )}
        </button>
      )}
      {/* Right-aligned filter funnel — click to change or clear the filter. */}
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            className={cn(
              "shrink-0 rounded p-0.5 hover:bg-accent hover:text-foreground",
              filtered ? "text-foreground" : "text-muted-foreground",
            )}
            aria-label={`Filter ${column.label}`}
            data-testid={`column-header-${column.key}`}
          >
            <Filter className={cn("size-3.5", filtered && "fill-current")} />
          </button>
        </PopoverTrigger>
        <PopoverContent align="end" className="w-60 p-2">
          <FilterControls
            column={column}
            filter={filter}
            set={set}
            assignableUsers={assignableUsers}
            allTags={allTags}
          />
          {filtered && (
            <button
              type="button"
              onClick={() => set(null)}
              className="mt-1 w-full rounded-md px-2 py-1 text-left text-sm text-muted-foreground hover:bg-accent"
            >
              Clear filter
            </button>
          )}
        </PopoverContent>
      </Popover>
      {/* Edit the field definition (custom-field columns only). */}
      {onEdit && (
        <button
          type="button"
          onClick={onEdit}
          className="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
          aria-label={`Edit ${column.label}`}
          data-testid={`column-edit-${column.key}`}
        >
          <Pencil className="size-3.5" />
        </button>
      )}
    </div>
  );
};

const FilterControls = ({
  column,
  filter,
  set,
  assignableUsers,
  allTags,
}: {
  column: ListColumn;
  filter: FilterValue | undefined;
  set: (value: FilterValue | null) => void;
  assignableUsers: AssigneeOption[];
  allTags: TagOption[];
}) => {
  switch (column.filter) {
    case "text": {
      const query = filter?.kind === "text" ? filter.query : "";
      return (
        <Input
          value={query}
          onChange={(e) =>
            set(e.target.value ? { kind: "text", query: e.target.value } : null)
          }
          placeholder={`Filter ${column.label.toLowerCase()}…`}
          className="h-8"
          aria-label={`Filter ${column.label}`}
        />
      );
    }
    case "due": {
      const current = filter?.kind === "due" ? filter : undefined;
      const mode = current?.mode ?? "all";
      const after = current?.after ?? "";
      const before = current?.before ?? "";
      // Merge a change with the rest of the due filter; collapse to "no filter"
      // when nothing meaningful is set.
      const update = (patch: {
        mode?: "all" | "has" | "none" | "overdue";
        after?: string;
        before?: string;
      }) => {
        const merged = { mode, after, before, ...patch };
        const next = {
          mode: merged.mode,
          after: merged.after || undefined,
          before: merged.before || undefined,
        };
        if (next.mode === "all" && !next.after && !next.before) {
          set(null);
          return;
        }
        set({ kind: "due", ...next });
      };
      const opts: Array<{ value: "all" | "has" | "none" | "overdue"; label: string }> = [
        { value: "all", label: "Any" },
        { value: "has", label: "Has a due date" },
        { value: "none", label: "No due date" },
        { value: "overdue", label: "Overdue" },
      ];
      return (
        <div className="flex flex-col gap-2">
          <div className="flex flex-col gap-0.5">
            {opts.map((o) => (
              <Choice
                key={o.value}
                active={mode === o.value}
                onClick={() => update({ mode: o.value })}
              >
                {o.label}
              </Choice>
            ))}
          </div>
          <div className="flex flex-col gap-0.5">
            <DateBound
              label="Due after"
              value={after}
              onChange={(v) => update({ after: v })}
            />
            <DateBound
              label="Due before"
              value={before}
              onChange={(v) => update({ before: v })}
            />
          </div>
        </div>
      );
    }
    case "date":
    case "presence": {
      const mode = filter?.kind === column.filter ? filter.mode : "all";
      const opts: Array<{ value: "all" | "has" | "none"; label: string }> = [
        { value: "all", label: "Any" },
        { value: "has", label: "Has a value" },
        { value: "none", label: "Empty" },
      ];
      return (
        <div className="flex flex-col gap-0.5">
          {opts.map((o) => (
            <Choice
              key={o.value}
              active={mode === o.value}
              onClick={() =>
                set(
                  o.value === "all"
                    ? null
                    : { kind: column.filter as "date" | "presence", mode: o.value },
                )
              }
            >
              {o.label}
            </Choice>
          ))}
        </div>
      );
    }
    case "boolean": {
      const mode = filter?.kind === "boolean" ? filter.mode : "all";
      const opts: Array<{ value: "all" | "true" | "false"; label: string }> = [
        { value: "all", label: "Any" },
        { value: "true", label: "Yes" },
        { value: "false", label: "No" },
      ];
      return (
        <div className="flex flex-col gap-0.5">
          {opts.map((o) => (
            <Choice
              key={o.value}
              active={mode === o.value}
              onClick={() =>
                set(o.value === "all" ? null : { kind: "boolean", mode: o.value })
              }
            >
              {o.label}
            </Choice>
          ))}
        </div>
      );
    }
    case "assignees": {
      const current =
        filter?.kind === "assignees"
          ? filter
          : { kind: "assignees" as const, iris: [], unassigned: false };
      const toggleIri = (iri: string, checked: boolean) => {
        const iris = checked
          ? [...current.iris, iri]
          : current.iris.filter((x) => x !== iri);
        const next = { kind: "assignees" as const, iris, unassigned: current.unassigned };
        set(isAssigneesActive(next) ? next : null);
      };
      return (
        <div className="flex max-h-56 flex-col gap-0.5 overflow-y-auto">
          <CheckRow
            checked={current.unassigned}
            onChange={(v) => {
              const next = { kind: "assignees" as const, iris: current.iris, unassigned: v };
              set(isAssigneesActive(next) ? next : null);
            }}
          >
            <span className="text-muted-foreground">Unassigned</span>
          </CheckRow>
          {assignableUsers.map((u) => (
            <CheckRow
              key={u["@id"]}
              checked={current.iris.includes(u["@id"])}
              onChange={(v) => toggleIri(u["@id"], v)}
            >
              {displayName(u)}
            </CheckRow>
          ))}
        </div>
      );
    }
    case "tags": {
      const current =
        filter?.kind === "tags"
          ? filter
          : { kind: "tags" as const, iris: [], untagged: false };
      const toggleIri = (iri: string, checked: boolean) => {
        const iris = checked
          ? [...current.iris, iri]
          : current.iris.filter((x) => x !== iri);
        const next = { kind: "tags" as const, iris, untagged: current.untagged };
        set(next.iris.length > 0 || next.untagged ? next : null);
      };
      return (
        <div className="flex max-h-56 flex-col gap-0.5 overflow-y-auto">
          <CheckRow
            checked={current.untagged}
            onChange={(v) => {
              const next = { kind: "tags" as const, iris: current.iris, untagged: v };
              set(next.iris.length > 0 || next.untagged ? next : null);
            }}
          >
            <span className="text-muted-foreground">Untagged</span>
          </CheckRow>
          {allTags.map((t) => (
            <CheckRow
              key={t["@id"]}
              checked={current.iris.includes(t["@id"])}
              onChange={(v) => toggleIri(t["@id"], v)}
            >
              {t.title}
            </CheckRow>
          ))}
        </div>
      );
    }
    case "select": {
      const options = column.definition?.config.options ?? [];
      const current =
        filter?.kind === "select"
          ? filter
          : { kind: "select" as const, keys: [], empty: false };
      const toggleKey = (key: string, checked: boolean) => {
        const keys = checked
          ? [...current.keys, key]
          : current.keys.filter((x) => x !== key);
        const next = { kind: "select" as const, keys, empty: current.empty };
        set(next.keys.length > 0 || next.empty ? next : null);
      };
      return (
        <div className="flex max-h-56 flex-col gap-0.5 overflow-y-auto">
          <CheckRow
            checked={current.empty}
            onChange={(v) => {
              const next = { kind: "select" as const, keys: current.keys, empty: v };
              set(next.keys.length > 0 || next.empty ? next : null);
            }}
          >
            <span className="text-muted-foreground">Empty</span>
          </CheckRow>
          {options.map((o) => (
            <CheckRow
              key={o.key}
              checked={current.keys.includes(o.key)}
              onChange={(v) => toggleKey(o.key, v)}
            >
              {o.label}
            </CheckRow>
          ))}
        </div>
      );
    }
  }
};

const isAssigneesActive = (v: {
  iris: string[];
  unassigned: boolean;
}): boolean => v.iris.length > 0 || v.unassigned;

export default ColumnHeaderMenu;
