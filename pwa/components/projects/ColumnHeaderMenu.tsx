import { useState } from "react";
import { ArrowDown, ArrowUp, ChevronsUpDown, Filter } from "lucide-react";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Input } from "@/components/ui/input";
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
}

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
}: ColumnHeaderMenuProps) => {
  const [open, setOpen] = useState(false);
  const sortedHere = sort?.key === column.key;
  const filtered = isFilterActive(filter);

  const set = (value: FilterValue | null) => onSetFilter(column.key, value);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          className={cn(
            "-mx-1 flex w-full items-center gap-1 rounded px-1 py-0.5 text-left font-medium hover:bg-accent",
            (sortedHere || filtered) && "text-foreground",
          )}
          aria-label={`${column.label} column options`}
          data-testid={`column-header-${column.key}`}
        >
          <span className="truncate">{column.label}</span>
          {sortedHere ? (
            sort.dir === "asc" ? (
              <ArrowUp className="size-3 shrink-0" />
            ) : (
              <ArrowDown className="size-3 shrink-0" />
            )
          ) : (
            <ChevronsUpDown className="size-3 shrink-0 opacity-40" />
          )}
          {filtered && (
            <Filter className="size-3 shrink-0 fill-current text-cyan-600 dark:text-cyan-400" />
          )}
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-60 p-2">
        {column.sortable && (
          <div className="mb-1 flex flex-col gap-0.5">
            <Choice
              active={sortedHere && sort.dir === "asc"}
              onClick={() => onSetSort({ key: column.key, dir: "asc" })}
            >
              <span className="flex items-center gap-2">
                <ArrowUp className="size-3.5" /> Sort ascending
              </span>
            </Choice>
            <Choice
              active={sortedHere && sort.dir === "desc"}
              onClick={() => onSetSort({ key: column.key, dir: "desc" })}
            >
              <span className="flex items-center gap-2">
                <ArrowDown className="size-3.5" /> Sort descending
              </span>
            </Choice>
            {sortedHere && (
              <Choice active={false} onClick={() => onSetSort(null)}>
                <span className="text-muted-foreground">Clear sort</span>
              </Choice>
            )}
          </div>
        )}

        {column.sortable && column.filter !== "presence" && (
          <div className="my-1 h-px bg-border" />
        )}

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
      const mode = filter?.kind === "due" ? filter.mode : "all";
      const opts: Array<{ value: "all" | "has" | "none" | "overdue"; label: string }> = [
        { value: "all", label: "Any" },
        { value: "has", label: "Has a due date" },
        { value: "none", label: "No due date" },
        { value: "overdue", label: "Overdue" },
      ];
      return (
        <div className="flex flex-col gap-0.5">
          {opts.map((o) => (
            <Choice
              key={o.value}
              active={mode === o.value}
              onClick={() =>
                set(o.value === "all" ? null : { kind: "due", mode: o.value })
              }
            >
              {o.label}
            </Choice>
          ))}
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
