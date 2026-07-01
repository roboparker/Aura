import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { dayKeyDiff, parseDayKey } from "@/lib/calendarDates";
import WorkspaceCalendar, {
  type CalendarEntry,
  type RescheduleScope,
} from "@/components/calendar/WorkspaceCalendar";
import TaskDetailDrawer from "@/components/tasks/TaskDetailDrawer";
import { type AssigneeOption } from "@/components/tasks/AssigneesCombobox";
import { type TagOption } from "@/components/tasks/TagsCombobox";
import { Alert, AlertDescription } from "@/components/ui/alert";

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

const CalendarPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();
  const currentUserIri = user ? `/users/${user.id}` : null;

  const spaceIri = activeSpace ? activeSpace["@id"] : null;

  const [entries, setEntries] = useState<CalendarEntry[]>([]);
  const [range, setRange] = useState<{ start: string; end: string } | null>(null);
  const [assignableUsers, setAssignableUsers] = useState<AssigneeOption[]>([]);
  const [allTags, setAllTags] = useState<TagOption[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Filters (derived options from the loaded entries).
  const [projectFilter, setProjectFilter] = useState("");
  const [assigneeFilter, setAssigneeFilter] = useState("");

  // Deep-linkable task drawer via `?task={id}`.
  const activeTaskId =
    typeof router.query.task === "string" ? router.query.task : null;
  const openTaskDetail = useCallback(
    (taskId: string) => {
      void router.push(
        { pathname: router.pathname, query: { ...router.query, task: taskId } },
        undefined,
        { shallow: true },
      );
    },
    [router],
  );
  const handleDrawerOpenChange = useCallback(
    (open: boolean) => {
      if (open) return;
      const query = { ...router.query };
      delete query.task;
      void router.push({ pathname: router.pathname, query }, undefined, { shallow: true });
    },
    [router],
  );

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  // Assignable users + tags feed the detail drawer's editors.
  useEffect(() => {
    if (!isAuthenticated) return;
    const init = {
      credentials: "include" as const,
      headers: { Accept: "application/ld+json" },
    };
    void (async () => {
      try {
        const [usersRes, tagsRes] = await Promise.all([
          fetch(`${ENTRYPOINT}/me/assignable-users`, init),
          fetch(`${ENTRYPOINT}/tags`, init),
        ]);
        if (usersRes.ok) {
          const data: Collection<AssigneeOption> = await usersRes.json();
          setAssignableUsers(data.member ?? data["hydra:member"] ?? []);
        }
        if (tagsRes.ok) {
          const data: Collection<TagOption> = await tagsRes.json();
          setAllTags(data.member ?? data["hydra:member"] ?? []);
        }
      } catch {
        // Non-fatal — the calendar still renders without drawer editors.
      }
    })();
  }, [isAuthenticated]);

  const loadEntries = useCallback(async () => {
    if (!spaceIri || !range) return;
    setIsLoading(true);
    setError(null);
    try {
      const url = `${ENTRYPOINT}/calendar?${new URLSearchParams({
        space: spaceIri,
        start: range.start,
        end: range.end,
      }).toString()}`;
      const res = await fetch(url, {
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
  }, [spaceIri, range]);

  useEffect(() => {
    void loadEntries();
  }, [loadEntries]);

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
          // Shift the anchor by the dragged delta (covers non-recurring moves
          // and "all occurrences"); preserves the task's time-of-day.
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
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to reschedule.");
      }
    },
    [loadEntries],
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

  if (authLoading || !isAuthenticated) {
    return (
      <div className="min-h-screen bg-background" />
    );
  }

  return (
    <>
      <Head>
        <title>Calendar - Madori</title>
      </Head>
      <main className="min-h-screen bg-background">
        <div className="max-w-7xl mx-auto px-4 py-8">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 className="text-2xl font-bold">Calendar</h1>
            <div className="flex flex-wrap items-center gap-2">
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
          </div>

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {activeSpace ? (
            <WorkspaceCalendar
              entries={visibleEntries}
              isLoading={isLoading}
              onRangeChange={onRangeChange}
              onOpen={openTaskDetail}
              onReschedule={reschedule}
            />
          ) : (
            <p className="text-sm text-muted-foreground">Loading space…</p>
          )}
        </div>
      </main>

      <TaskDetailDrawer
        taskId={activeTaskId}
        open={Boolean(activeTaskId)}
        onOpenChange={handleDrawerOpenChange}
        currentUserIri={currentUserIri}
        assignableUsers={assignableUsers}
        allTags={allTags}
        onTaskChanged={() => void loadEntries()}
        onTaskDeleted={() => void loadEntries()}
      />
    </>
  );
};

export default CalendarPage;
