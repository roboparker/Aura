import { useCallback, useEffect, useMemo, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { dayKeyDiff, parseDayKey } from "@/lib/calendarDates";
import WorkspaceCalendar, {
  type CalendarEntry,
  type RescheduleScope,
} from "@/components/calendar/WorkspaceCalendar";
import { Alert, AlertDescription } from "@/components/ui/alert";

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
  const [projectFilter, setProjectFilter] = useState("");
  const [assigneeFilter, setAssigneeFilter] = useState("");

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
          (projectFilter === "" || e.project?.["@id"] === projectFilter) &&
          (assigneeFilter === "" || e.assignees.some((a) => a["@id"] === assigneeFilter)),
      ),
    [entries, projectFilter, assigneeFilter],
  );

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        {!projectIri && (
          <select
            value={projectFilter}
            onChange={(e) => setProjectFilter(e.target.value)}
            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
            aria-label="Filter by project"
            data-testid="calendar-project-filter"
          >
            <option value="">All projects</option>
            {projectOptions.map(([iri, title]) => (
              <option key={iri} value={iri}>
                {title}
              </option>
            ))}
          </select>
        )}
        <select
          value={assigneeFilter}
          onChange={(e) => setAssigneeFilter(e.target.value)}
          className="h-8 rounded-md border border-input bg-background px-2 text-sm"
          aria-label="Filter by assignee"
          data-testid="calendar-assignee-filter"
        >
          <option value="">All assignees</option>
          {assigneeOptions.map(([iri, name]) => (
            <option key={iri} value={iri}>
              {name}
            </option>
          ))}
        </select>
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
