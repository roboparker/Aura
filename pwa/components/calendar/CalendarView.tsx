import { useCallback, useEffect, useMemo, useState } from "react";
import { Filter } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { dayKeyDiff, parseDayKey } from "@/lib/calendarDates";
import WorkspaceCalendar, {
  type CalendarEntry,
  type RescheduleScope,
} from "@/components/calendar/WorkspaceCalendar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { cn } from "@/lib/utils";

/**
 * Multi-select filter dropdown mirroring the project list view's column
 * filters — a button (with an active count) that opens a checkbox list.
 */
const MultiFilter = ({
  label,
  options,
  selected,
  onChange,
  testId,
}: {
  label: string;
  options: [value: string, label: string][];
  selected: Set<string>;
  onChange: (next: Set<string>) => void;
  testId?: string;
}) => {
  const [open, setOpen] = useState(false);
  const count = selected.size;
  const toggle = (value: string, checked: boolean) => {
    const next = new Set(selected);
    if (checked) next.add(value);
    else next.delete(value);
    onChange(next);
  };
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={cn("h-8 gap-1", count > 0 && "border-primary/60 text-foreground")}
          data-testid={testId}
        >
          <Filter className="h-3.5 w-3.5" />
          {label}
          {count > 0 && <span className="text-muted-foreground">({count})</span>}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" className="max-h-72 w-56 overflow-y-auto p-1">
        {options.length === 0 ? (
          <p className="px-2 py-1.5 text-xs text-muted-foreground">No options</p>
        ) : (
          options.map(([value, optionLabel]) => (
            <label
              key={value}
              className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent"
            >
              <Checkbox
                checked={selected.has(value)}
                onCheckedChange={(c) => toggle(value, c === true)}
              />
              <span className="truncate">{optionLabel}</span>
            </label>
          ))
        )}
        {count > 0 && (
          <button
            type="button"
            onClick={() => onChange(new Set())}
            className="mt-1 w-full rounded px-2 py-1.5 text-left text-xs text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            Clear
          </button>
        )}
      </PopoverContent>
    </Popover>
  );
};

interface CalendarViewProps {
  /** Space whose tasks the calendar projects (`/spaces/{id}` IRI). */
  spaceIri: string;
  /** When set, narrow to one project (the project-tab calendar) and hide the
   *  project filter. */
  projectIri?: string;
  /** Open the task detail drawer (the host page owns the drawer). */
  onOpen: (taskId: string) => void;
  /** Notify the host that a task moved, so it can refresh its own list. */
  onTasksChanged?: () => void;
  /** Bump to force a re-fetch (e.g. after the host's drawer edits a task). */
  refreshSignal?: number;
}

/**
 * Self-contained calendar surface shared by the top-level `/calendar` page and
 * the project detail's Calendar tab — same component, the only difference being
 * the project tab passes `projectIri` to filter to one project. Owns fetching
 * (`GET /calendar`), the assignee/project filters, and drag-to-reschedule; the
 * host page owns the task detail drawer (via `onOpen`).
 */
const CalendarView = ({
  spaceIri,
  projectIri,
  onOpen,
  onTasksChanged,
  refreshSignal,
}: CalendarViewProps) => {
  const [entries, setEntries] = useState<CalendarEntry[]>([]);
  const [range, setRange] = useState<{ start: string; end: string } | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [projectFilter, setProjectFilter] = useState<Set<string>>(new Set());
  const [assigneeFilter, setAssigneeFilter] = useState<Set<string>>(new Set());

  const loadEntries = useCallback(async () => {
    if (!range) return;
    setIsLoading(true);
    setError(null);
    try {
      const params: Record<string, string> = {
        space: spaceIri,
        start: range.start,
        end: range.end,
      };
      if (projectIri) params.project = projectIri;
      const res = await fetch(`${ENTRYPOINT}/calendar?${new URLSearchParams(params).toString()}`, {
        credentials: "include",
        headers: { Accept: "application/json" },
      });
      if (!res.ok) throw new Error("Failed to load calendar.");
      const data: { entries?: CalendarEntry[] } = await res.json();
      setEntries(data.entries ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load calendar.");
    } finally {
      setIsLoading(false);
    }
  }, [spaceIri, projectIri, range]);

  useEffect(() => {
    void loadEntries();
  }, [loadEntries]);

  // Host-driven refetch (drawer edits, external changes) without disturbing
  // the current view/anchor. No-op on the initial mount (range not set yet).
  useEffect(() => {
    void loadEntries();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [refreshSignal]);

  const onRangeChange = useCallback((start: string, end: string) => {
    setRange((prev) =>
      prev && prev.start === start && prev.end === end ? prev : { start, end },
    );
  }, []);

  const reschedule = useCallback(
    async (entry: CalendarEntry, targetKey: string, scope: RescheduleScope) => {
      try {
        if (entry.recurring && scope === "single") {
          const res = await fetch(
            `${ENTRYPOINT}/tasks/${encodeURIComponent(entry.taskId)}/detach-occurrence`,
            {
              method: "POST",
              credentials: "include",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ date: entry.occurrenceDate, dueDate: targetKey }),
            },
          );
          if (!res.ok) throw new Error("Failed to move occurrence.");
        } else {
          // Shift the anchor by the dragged delta (non-recurring move + "all
          // occurrences"); preserves the task's time-of-day.
          const deltaDays = dayKeyDiff(entry.occurrenceDate, targetKey);
          const base = entry.dueDate ? new Date(entry.dueDate) : parseDayKey(entry.occurrenceDate);
          base.setDate(base.getDate() + deltaDays);
          const res = await fetch(`${ENTRYPOINT}/tasks/${encodeURIComponent(entry.taskId)}`, {
            method: "PATCH",
            credentials: "include",
            headers: { "Content-Type": "application/merge-patch+json" },
            body: JSON.stringify({ dueDate: base.toISOString() }),
          });
          if (!res.ok) throw new Error("Failed to reschedule task.");
        }
        await loadEntries();
        onTasksChanged?.();
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to reschedule.");
      }
    },
    [loadEntries, onTasksChanged],
  );

  const projectOptions = useMemo(() => {
    const seen = new Map<string, string>();
    for (const e of entries) {
      if (e.project) seen.set(e.project["@id"], e.project.title);
    }
    return [...seen.entries()].sort((a, b) => a[1].localeCompare(b[1]));
  }, [entries]);

  const assigneeOptions = useMemo(() => {
    const seen = new Map<string, string>();
    for (const e of entries) {
      for (const a of e.assignees) {
        const name =
          a.nickname || `${a.givenName ?? ""} ${a.familyName ?? ""}`.trim() || a["@id"];
        seen.set(a["@id"], name);
      }
    }
    return [...seen.entries()].sort((a, b) => a[1].localeCompare(b[1]));
  }, [entries]);

  const visibleEntries = useMemo(
    () =>
      entries.filter(
        (e) =>
          (projectFilter.size === 0 ||
            (e.project != null && projectFilter.has(e.project["@id"]))) &&
          (assigneeFilter.size === 0 ||
            e.assignees.some((a) => assigneeFilter.has(a["@id"]))),
      ),
    [entries, projectFilter, assigneeFilter],
  );

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        {!projectIri && (
          <MultiFilter
            label="Projects"
            options={projectOptions}
            selected={projectFilter}
            onChange={setProjectFilter}
            testId="calendar-project-filter"
          />
        )}
        <MultiFilter
          label="Assignees"
          options={assigneeOptions}
          selected={assigneeFilter}
          onChange={setAssigneeFilter}
          testId="calendar-assignee-filter"
        />
      </div>

      {error && (
        <Alert variant="destructive" className="mb-4">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <WorkspaceCalendar
        entries={visibleEntries}
        isLoading={isLoading}
        onRangeChange={onRangeChange}
        onOpen={onOpen}
        onReschedule={reschedule}
      />
    </div>
  );
};

export default CalendarView;
