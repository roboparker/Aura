import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { CalendarRange } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import BoardTimeline from "@/components/boards/BoardTimeline";
import TaskDetailDrawer from "@/components/tasks/TaskDetailDrawer";
import { type AssigneeOption } from "@/components/tasks/AssigneesCombobox";
import { type TagOption } from "@/components/tasks/TagsCombobox";
import { type CustomFieldValuePair } from "@/components/tasks/CustomFieldValueList";
import { pageTitle } from "@/lib/pageTitle";

/**
 * `/timeline` — the cross-board Gantt for the active space (#timeline).
 *
 * The board tab charts one board; this unions every **timeline-enabled** board
 * in the space and groups rows by board instead of by section. Boards that
 * haven't turned Timeline on are left out rather than shown empty — the toggle
 * is what says "these dates are meaningful".
 *
 * Read-only here: the space view is for seeing how work lines up across boards,
 * so bars aren't draggable. Rescheduling stays on the owning board's tab, where
 * the start-field config lives.
 */

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

const membersOf = <T,>(c: Collection<T>): T[] =>
  c.member ?? c["hydra:member"] ?? [];

interface SpaceBoard {
  "@id": string;
  id: string;
  title: string;
  timelineEnabled?: boolean;
}

interface SpaceTask {
  "@id": string;
  id: string;
  title: string;
  dueDate: string | null;
  completedOn: string | null;
  section: string | null;
  board: string | { "@id": string } | null;
  customFieldValues: CustomFieldValuePair[];
}

const boardIriOf = (task: SpaceTask): string | null => {
  if (!task.board) return null;
  return typeof task.board === "string" ? task.board : task.board["@id"];
};

const TimelinePage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();
  const currentUserIri = user ? `/users/${user.id}` : null;

  const [boards, setBoards] = useState<SpaceBoard[]>([]);
  const [tasks, setTasks] = useState<SpaceTask[]>([]);
  const [startFieldIri, setStartFieldIri] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [assignableUsers, setAssignableUsers] = useState<AssigneeOption[]>([]);
  const [allTags, setAllTags] = useState<TagOption[]>([]);
  const [refreshKey, setRefreshKey] = useState(0);

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

  const spaceIri = activeSpace?.["@id"] ?? null;

  useEffect(() => {
    if (!isAuthenticated || !spaceIri) return;
    const init = {
      credentials: "include" as const,
      headers: { Accept: "application/ld+json" },
    };
    let live = true;

    void (async () => {
      setLoading(true);
      try {
        const [boardsRes, tasksRes, fieldsRes, usersRes, tagsRes] = await Promise.all([
          fetch(`${ENTRYPOINT}/boards?space=${encodeURIComponent(spaceIri)}`, init),
          fetch(
            `${ENTRYPOINT}/tasks?board.space=${encodeURIComponent(spaceIri)}&itemsPerPage=100`,
            init,
          ),
          fetch(`${ENTRYPOINT}/global_custom_field_definitions`, init),
          fetch(`${ENTRYPOINT}/me/assignable-users`, init),
          fetch(`${ENTRYPOINT}/tags`, init),
        ]);
        if (!live) return;

        if (boardsRes.ok) setBoards(membersOf<SpaceBoard>(await boardsRes.json()));
        if (tasksRes.ok) setTasks(membersOf<SpaceTask>(await tasksRes.json()));
        if (fieldsRes.ok) {
          // The canonical Start date field is the same instance-wide one every
          // timeline-enabled board attaches, so one lookup covers the space.
          const defs = membersOf<{ "@id": string; systemKey?: string | null }>(
            await fieldsRes.json(),
          );
          const start = defs.find((d) => d.systemKey === "timeline_start");
          setStartFieldIri(start?.["@id"] ?? null);
        }
        if (usersRes.ok) setAssignableUsers(membersOf<AssigneeOption>(await usersRes.json()));
        if (tagsRes.ok) setAllTags(membersOf<TagOption>(await tagsRes.json()));
      } catch {
        // Non-fatal: the empty state below explains there's nothing to chart.
      } finally {
        if (live) setLoading(false);
      }
    })();

    return () => {
      live = false;
    };
  }, [isAuthenticated, spaceIri, refreshKey]);

  // Only boards with Timeline turned on take part; their tasks are the rows.
  const timelineBoards = useMemo(
    () => boards.filter((b) => b.timelineEnabled === true),
    [boards],
  );
  const boardIris = useMemo(
    () => new Set(timelineBoards.map((b) => b["@id"])),
    [timelineBoards],
  );
  const chartTasks = useMemo(
    () => tasks.filter((t) => {
      const iri = boardIriOf(t);
      return iri !== null && boardIris.has(iri);
    }),
    [tasks, boardIris],
  );

  // One group per participating board (the board view groups by section).
  const groups = useMemo(
    () => timelineBoards.map((b) => ({ "@id": b["@id"], id: b.id, title: b.title })),
    [timelineBoards],
  );

  if (authLoading || !isAuthenticated) {
    return <div className="min-h-screen bg-background" />;
  }

  return (
    <>
      <Head>
        <title>{pageTitle("Timeline")}</title>
      </Head>
      <div className="min-h-screen bg-background">
        <div className="w-full px-4 py-8">
          <h1 className="mb-1 text-2xl font-bold">Timeline</h1>
          <p className="mb-4 text-sm text-muted-foreground">
            Every board in this space with Timeline turned on, in one chart.
            Reschedule from a board&apos;s own Timeline tab.
          </p>

          {!activeSpace || loading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : timelineBoards.length === 0 ? (
            <div
              className="flex flex-col items-center justify-center gap-2 rounded-md border border-dashed py-16 text-center"
              data-testid="space-timeline-empty"
            >
              <CalendarRange className="h-8 w-8 text-muted-foreground" aria-hidden />
              <p className="font-medium">No boards are on the timeline yet</p>
              <p className="max-w-sm text-sm text-muted-foreground">
                Turn Timeline on in a board&apos;s Settings and its scheduled
                tasks will appear here alongside every other board&apos;s.
              </p>
            </div>
          ) : (
            <BoardTimeline
              spaceId={activeSpace.id}
              tasks={chartTasks}
              sections={groups}
              groupBy={boardIriOf}
              enabled
              readOnly
              startFieldIri={startFieldIri}
              onOpenTask={(t) => openTaskDetail(t.id)}
              // Never called while readOnly, but the props are required.
              onMoveDue={() => {}}
              onMoveStart={() => {}}
            />
          )}
        </div>
      </div>

      <TaskDetailDrawer
        taskId={activeTaskId}
        open={Boolean(activeTaskId)}
        onOpenChange={handleDrawerOpenChange}
        currentUserIri={currentUserIri}
        assignableUsers={assignableUsers}
        allTags={allTags}
        onTaskChanged={() => setRefreshKey((k) => k + 1)}
        onTaskDeleted={() => setRefreshKey((k) => k + 1)}
      />
    </>
  );
};

export default TimelinePage;
