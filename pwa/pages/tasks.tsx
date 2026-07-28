import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { AlertTriangle, Filter, X } from "lucide-react";
import {
  DndContext,
  DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
} from "@dnd-kit/core";
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import CalendarView from "@/components/calendar/CalendarView";
import TasksBoardView from "@/components/tasks/TasksBoardView";
import { ENTRYPOINT } from "@/config/entrypoint";
import { trackEvent } from "@/lib/analytics";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { type CommentLiveEvent } from "@/lib/useCommentLiveUpdates";
import { useUndoableDelete } from "@/lib/useUndoableDelete";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import AssigneesCombobox, {
  type AssigneeOption,
} from "@/components/tasks/AssigneesCombobox";
import TagsCombobox from "@/components/tasks/TagsCombobox";
import { type Comment } from "@/components/common/CommentsPanel";
import { type Attachment } from "@/components/tasks/AttachmentsPanel";
import DueDateCell from "@/components/tasks/DueDateCell";
import NewTaskRow, { type NewTaskInput } from "@/components/tasks/NewTaskRow";
import SortableHeader from "@/components/tasks/SortableHeader";
import TaskRow from "@/components/tasks/TaskRow";
import {
  DEFAULT_SORT,
  dueDateStatus,
  plainTextDescription,
  removeComment,
  type RecurrenceRule,
  type Reminder,
  type SortKey,
  type SortState,
  type Tag,
  type Task,
} from "@/components/tasks/taskHelpers";
import TaskDetailDrawer from "@/components/tasks/TaskDetailDrawer";
import UserAvatar from "@/components/user/UserAvatar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { SkeletonList } from "@/components/ui/skeleton";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { displayName } from "@/lib/userDisplay";
import {
  Table,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { cn } from "@/lib/utils";

interface BoardMembership {
  "@id": string;
  title: string;
  members: Array<{ "@id": string }>;
}

type TaskView = "list" | "board" | "calendar";

interface Collection<T> {
  // API Platform 4 emits JSON-LD 1.1 (`member`); older versions use `hydra:member`.
  member?: T[];
  "hydra:member"?: T[];
}

// "all" shows every task; "me" filters to the current user; an IRI string
// filters to that specific assignable user. Stored as a single value rather
// than three separate flags so each setter call replaces the previous filter.
type AssigneeFilter = "all" | "me" | string;

const Tasks = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();
  const currentUserIri = user ? `/users/${user.id}` : null;

  // List / Board / Calendar view, mirrored in the URL (`?view=`) so it's
  // shareable and survives refresh. Anything unrecognised falls back to list.
  const view: TaskView =
    router.query.view === "board" || router.query.view === "calendar"
      ? router.query.view
      : "list";
  const setView = useCallback(
    (next: TaskView) => {
      const query = { ...router.query };
      if (next === "list") delete query.view;
      else query.view = next;
      void router.push({ pathname: router.pathname, query }, undefined, {
        shallow: true,
      });
    },
    [router],
  );
  // Bumped on drawer edits so the Calendar view (which fetches its own data)
  // re-syncs after a reschedule/complete.
  const [calendarRefresh, setCalendarRefresh] = useState(0);

  // Deep-linkable task detail: the open task lives in the URL (`?task={id}`)
  // so the drawer survives refresh/share. Shallow routing keeps the list
  // mounted underneath.
  const activeTaskId =
    typeof router.query.task === "string" ? router.query.task : null;
  const openTaskDetail = useCallback(
    (task: Task) => {
      void router.push(
        { pathname: router.pathname, query: { ...router.query, task: task.id } },
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
      void router.push({ pathname: router.pathname, query }, undefined, {
        shallow: true,
      });
    },
    [router],
  );
  // The Calendar view hands back a bare task id; open the drawer the same way.
  const openTaskById = useCallback(
    (taskId: string) => {
      void router.push(
        { pathname: router.pathname, query: { ...router.query, task: taskId } },
        undefined,
        { shallow: true },
      );
    },
    [router],
  );
  // Both `/tasks` and `/my-tasks` mount this component. The latter pins the
  // assignee filter to the logged-in user and hides the picker, so the page
  // is effectively a fixed "everything assigned to me" view.
  const isMyTasksPage = router.pathname === "/my-tasks";
  const pageTitle = isMyTasksPage ? "My Tasks" : "Tasks";

  const [tasks, setTasks] = useState<Task[]>([]);
  // Mirror of `tasks` for handlers that need the latest committed
  // state without relying on closure-captured props. Used by
  // `handleToggle` to determine the toggle direction from the
  // freshest data — without this, a row whose closure pre-dates the
  // last optimistic / response merge can compute the wrong
  // `nextCompletedOn` and re-pin the checkbox to its prior state
  // (the long-running tasks.spec flake).
  const tasksRef = useRef<Task[]>(tasks);
  useEffect(() => {
    tasksRef.current = tasks;
  }, [tasks]);
  const [allTags, setAllTags] = useState<Tag[]>([]);
  const [assignableUsers, setAssignableUsers] = useState<AssigneeOption[]>([]);
  // Map of board IRI → set of member IRIs. Used to filter the assignee
  // picker per task (board tasks accept only that board's members; the
  // server-side validator enforces the same rule).
  const [boardMembers, setBoardMembers] = useState<Map<string, Set<string>>>(
    new Map(),
  );
  const [boardTitles, setBoardTitles] = useState<Map<string, string>>(
    new Map(),
  );
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [sort, setSort] = useState<SortState>(DEFAULT_SORT);
  const [assigneeFilter, setAssigneeFilter] = useState<AssigneeFilter>(
    isMyTasksPage ? "me" : "all",
  );
  const [overdueOnly, setOverdueOnly] = useState(false);
  // Lazy-loaded comments per task IRI. Undefined entry = not yet fetched;
  // empty array = fetched and there are none. The TaskRow uses the
  // distinction to show "Comments" vs "Comments (0)" before the first load.
  const [commentsByTask, setCommentsByTask] = useState<
    Record<string, Comment[] | undefined>
  >({});
  const [loadingCommentsFor, setLoadingCommentsFor] = useState<Set<string>>(
    () => new Set(),
  );

  const sensors = useSensors(
    // Require an 8px drag before activating so a quick click on the grip
    // doesn't get misinterpreted as a reorder attempt.
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  // The navbar's global search drops users on /tasks?search=X. Forward
  // that to the API so the listing only shows matching tasks.
  const searchQuery = typeof router.query.search === "string"
    ? router.query.search
    : "";

  const loadData = useCallback(async (signal?: AbortSignal) => {
    // Snapshot tasks-state identity at the start of the fan-out. If
    // anything mutates `tasks` between now and when our response lands
    // (a handleToggle / handleCreate / etc. firing optimistically),
    // the reference will change and we drop the response on the floor
    // — otherwise the late server snapshot stomps the user's
    // optimistic edit back to whatever was committed when the GET was
    // issued. This is the root cause of the long-running uncheck flake
    // (#205): debug logs showed two `loadData` responses landing
    // *after* the optimistic uncomplete, each overwriting tasks state.
    // AbortController on the effect (#193) catches one source but
    // can't catch races where the loadData was already past the
    // signal check, and the trace in #205 shows additional loadData
    // calls with no matching effect-mount log — covering whatever the
    // additional source is, this guard makes the responses safe to
    // arrive late.
    const tasksSnapshot = tasksRef.current;
    setError(null);
    try {
      // Tasks, tags, the assignable-users universe, and the boards-with-
      // members set all load in parallel — the page needs the boards to
      // know which assignable users are valid for each board task.
      //
      // The optional AbortSignal lets callers cancel an in-flight fan-out
      // when they re-fire (the auth useEffect cleans up an outgoing call
      // before issuing the next one — see #193). Without that, React 18
      // strict-mode's mount/cleanup/remount cycle leaves two parallel
      // loadData calls racing each other; whichever response lands later
      // can stomp the freshly-applied optimistic state of an in-flight
      // toggle PATCH with the *pre-PATCH* server snapshot it observed at
      // request time.
      const tasksUrl = searchQuery.trim().length > 0
        ? `${ENTRYPOINT}/tasks?search=${encodeURIComponent(searchQuery)}`
        : `${ENTRYPOINT}/tasks`;
      const init = {
        credentials: "include" as const,
        headers: { Accept: "application/ld+json" },
        signal,
      };
      const [tasksRes, tagsRes, assignablesRes, boardsRes] = await Promise.all([
        fetch(tasksUrl, init),
        fetch(`${ENTRYPOINT}/tags`, init),
        fetch(`${ENTRYPOINT}/me/assignable-users`, init),
        fetch(`${ENTRYPOINT}/boards`, init),
      ]);
      if (!tasksRes.ok) {
        throw new Error("Failed to load tasks.");
      }
      if (!tagsRes.ok) {
        throw new Error("Failed to load tags.");
      }
      if (!assignablesRes.ok) {
        throw new Error("Failed to load assignable users.");
      }
      if (!boardsRes.ok) {
        throw new Error("Failed to load boards.");
      }
      const tasksData: Collection<Task> = await tasksRes.json();
      const tagsData: Collection<Tag> = await tagsRes.json();
      const assignablesData: Collection<AssigneeOption> = await assignablesRes.json();
      const boardsData: Collection<BoardMembership> = await boardsRes.json();
      // One last guard against the strict-mode cleanup landing between
      // the JSON parses and the setStates: if the signal aborted while
      // we were parsing, drop the responses on the floor.
      if (signal?.aborted) {
        return;
      }
      // Identity guard — see comment at the top of this callback. If a
      // local mutation slipped in while we were waiting, skip stomping the
      // tasks list; the tag / assignable-user / board-member lists below
      // are still safe to refresh since none carry optimistic UI state.
      if (tasksRef.current === tasksSnapshot) {
        setTasks(tasksData.member ?? tasksData["hydra:member"] ?? []);
      }
      setAllTags(tagsData.member ?? tagsData["hydra:member"] ?? []);
      setAssignableUsers(
        assignablesData.member ?? assignablesData["hydra:member"] ?? [],
      );
      const boardMap = new Map<string, Set<string>>();
      const titleMap = new Map<string, string>();
      const boards = boardsData.member ?? boardsData["hydra:member"] ?? [];
      for (const board of boards) {
        boardMap.set(
          board["@id"],
          new Set(board.members.map((m) => m["@id"])),
        );
        titleMap.set(board["@id"], board.title);
      }
      setBoardMembers(boardMap);
      setBoardTitles(titleMap);
    } catch (err) {
      // AbortError is a normal control-flow signal here, not a failure —
      // the caller cancelled because the effect is being torn down.
      if (err instanceof DOMException && err.name === "AbortError") {
        return;
      }
      setError(err instanceof Error ? err.message : "Failed to load tasks.");
    } finally {
      if (!signal?.aborted) {
        setIsLoading(false);
      }
    }
  }, [searchQuery]);

  // /tasks and /my-tasks render the *same* component instance via the
  // re-export in pages/my-tasks.tsx, so the `useState` initializer above
  // only runs once. Without this effect the filter (and the cached tasks
  // list) would carry over from the previous route. Re-pin the filter and
  // refetch whenever the page mode flips — this also covers the initial
  // mount once `isAuthenticated` becomes true.
  //
  // The cleanup function aborts the in-flight loadData fan-out when the
  // effect re-runs or the page unmounts (#193). Crucially, that
  // includes React 18 strict-mode's synthetic mount → cleanup → re-mount
  // cycle in dev: without this, the strict-mode cleanup is a no-op and
  // the first loadData's stale response can land mid-toggle and overwrite
  // the optimistic uncheck with whatever the server had at the time the
  // fetch was issued — surfacing as the long-running tasks-toggle race
  // (#193).
  useEffect(() => {
    setAssigneeFilter(isMyTasksPage ? "me" : "all");
    if (isAuthenticated) {
      const ctl = new AbortController();
      void loadData(ctl.signal);
      return () => {
        ctl.abort();
      };
    }
    return undefined;
  }, [isMyTasksPage, isAuthenticated, loadData]);

  const handleCreate = async (input: NewTaskInput) => {
    const trimmed = input.title.trim();
    if (!trimmed) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/tasks`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          title: trimmed,
          description: input.description,
          tags: input.tags,
          dueDate: input.dueDate,
          assignees: input.assignees,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to create task.",
        );
      }
      // Splice the freshly-created task into local state from the POST
      // response instead of refetching the whole list. The previous
      // `await loadData()` issued four parallel GETs and a single
      // network blip on any of them dropped the new task on the floor —
      // observed as a flaky "Failed to fetch" alert under E2E parallel
      // load. The POST response already includes every relation the row
      // needs (owner, tags, assignees, attachments, position assigned
      // server-side), so a local insert is both faster and resilient.
      const created: Task = await res.json();
      setTasks((prev) => [created, ...prev]);
      trackEvent("task-create");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create task.");
      // Re-throw so NewTaskRow keeps the user's draft for retry.
      throw err;
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleToggle = async (task: Task) => {
    // Optimistic toggle: flip the checkbox immediately so the UI feels
    // responsive and so controlled-input assertions in tests see the new
    // state without waiting for the server round-trip.
    //
    // The toggle direction is read from `tasksRef.current` rather than
    // the closure's `task` prop. The row's onChange captures whatever
    // `task` was passed during its last render, so a render that
    // landed AFTER the row was set up (response merge, parent re-render
    // for an unrelated piece of state) can leave the closure's
    // `completedOn` lagging the actual committed state — flipping the
    // wrong direction and producing a no-op that surfaces as the
    // long-running tasks.spec flake. The ref always points at the
    // latest committed state, so `previousCompletedOn` and
    // `nextCompletedOn` are always derived from the truth React just
    // rendered.
    const current =
      tasksRef.current.find((t) => t["@id"] === task["@id"]) ?? task;
    const previousCompletedOn = current.completedOn;
    const nextCompletedOn = current.completedOn
      ? null
      : new Date().toISOString();
    // Completing a recurring task spawns a fresh occurrence server-side; we
    // need to refetch so that new row appears in the list. Toggling *off*
    // (un-completing) doesn't need a refetch.
    const willSpawnNextOccurrence =
      !current.completedOn &&
      current.recurrenceRule !== null &&
      current.dueDate !== null;
    setTasks((prev) =>
      prev.map((t) =>
        t["@id"] === task["@id"] ? { ...t, completedOn: nextCompletedOn } : t,
      ),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ completedOn: nextCompletedOn }),
      });
      if (!res.ok) {
        throw new Error("Failed to update task.");
      }
      // Trust the optimistic state. The previous merge-back pattern
      // (PR 3 of #185) was added to defeat the toBeChecked flake by
      // forcing convergence to the server response, but the response
      // body — even when ok — could shadow the just-set optimistic
      // `completedOn` with a value that differed by milliseconds from
      // the row's other fields, and the resulting re-render appears to
      // have BEEN the source of the flake rather than its cure. With a
      // pure optimistic update (and `tasksRef` driving the toggle
      // direction from the latest committed state), the row only
      // re-renders once per click — leaving Playwright's
      // not.toBeChecked() with a stable target to poll against.
      if (willSpawnNextOccurrence) {
        await loadData();
      }
    } catch (err) {
      setTasks((prev) =>
        prev.map((t) =>
          t["@id"] === task["@id"] ? { ...t, completedOn: previousCompletedOn } : t,
        ),
      );
      setError(err instanceof Error ? err.message : "Failed to update task.");
    }
  };

  // Where each optimistically-removed task sat, so Undo restores it to its
  // original position instead of dropping it at the end of the list.
  const deletedIndex = useRef(new Map<string, number>());

  const handleDelete = useUndoableDelete<Task>({
    keyOf: (task) => task["@id"],
    remove: (task) => {
      setError(null);
      const index = tasks.findIndex((t) => t["@id"] === task["@id"]);
      if (index !== -1) {
        deletedIndex.current.set(task["@id"], index);
      }
      setTasks((prev) => prev.filter((t) => t["@id"] !== task["@id"]));
    },
    restore: (task) => {
      setTasks((prev) => {
        if (prev.some((t) => t["@id"] === task["@id"])) return prev;
        const next = [...prev];
        const index = deletedIndex.current.get(task["@id"]) ?? next.length;
        next.splice(Math.min(index, next.length), 0, task);
        return next;
      });
      deletedIndex.current.delete(task["@id"]);
    },
    request: async (task) => {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) {
        throw new Error("Failed to delete task.");
      }
      deletedIndex.current.delete(task["@id"]);
    },
    message: (task) => `Deleted “${task.title}”`,
    onError: (_task, err) => {
      setError(err instanceof Error ? err.message : "Failed to delete task.");
    },
  });

  const handleTitleChange = async (task: Task, nextTitle: string) => {
    // Optimistic title update — input has already returned to read mode.
    const previous = tasks;
    setTasks(tasks.map((t) => (t["@id"] === task["@id"] ? { ...t, title: nextTitle } : t)));
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ title: nextTitle }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to update title.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update title.");
    }
  };

  const handleDescriptionChange = async (task: Task, nextDescription: string | null) => {
    // Optimistic description update.
    const previous = tasks;
    setTasks(
      tasks.map((t) =>
        t["@id"] === task["@id"] ? { ...t, description: nextDescription } : t,
      ),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ description: nextDescription }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to update description.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update description.");
    }
  };

  const handleRecurrenceChange = async (
    task: Task,
    nextRule: RecurrenceRule | null,
  ) => {
    const previous = tasks;
    setTasks(
      tasks.map((t) =>
        t["@id"] === task["@id"] ? { ...t, recurrenceRule: nextRule } : t,
      ),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ recurrenceRule: nextRule }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to update recurrence.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update recurrence.");
    }
  };

  const handleDueDateChange = async (task: Task, nextDueDate: string | null) => {
    // Optimistic due-date update; rollback on server reject.
    const previous = tasks;
    setTasks(
      tasks.map((t) => (t["@id"] === task["@id"] ? { ...t, dueDate: nextDueDate } : t)),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ dueDate: nextDueDate }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to update due date.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update due date.");
    }
  };

  const handleRemindersChange = async (
    task: Task,
    nextReminders: Reminder[] | null,
  ) => {
    const previous = tasks;
    setTasks(
      tasks.map((t) =>
        t["@id"] === task["@id"] ? { ...t, reminders: nextReminders } : t,
      ),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ reminders: nextReminders ?? [] }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to update reminders.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update reminders.");
    }
  };

  const handleAssigneesChange = async (task: Task, nextIris: string[]) => {
    const previous = tasks;
    const nextAssignees = nextIris
      .map((iri) => assignableUsers.find((u) => u["@id"] === iri))
      .filter((u): u is AssigneeOption => Boolean(u));
    setTasks(
      tasks.map((t) =>
        t["@id"] === task["@id"] ? { ...t, assignees: nextAssignees } : t,
      ),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ assignees: nextIris }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to update assignees.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update assignees.");
    }
  };

  const handleTagsChange = async (task: Task, nextTagIris: string[]) => {
    // Optimistic update so badges appear instantly. Roll back on server reject.
    const previous = tasks;
    const nextTags = nextTagIris
      .map((iri) => allTags.find((t) => t["@id"] === iri))
      .filter((t): t is Tag => Boolean(t));
    setTasks(tasks.map((t) => (t["@id"] === task["@id"] ? { ...t, tags: nextTags } : t)));
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ tags: nextTagIris }),
      });
      if (!res.ok) {
        throw new Error("Failed to update tags.");
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update tags.");
    }
  };

  const handleLoadComments = useCallback(
    async (task: Task) => {
      const taskIri = task["@id"];
      // Already loaded or in flight — bail out.
      if (commentsByTask[taskIri] !== undefined) return;
      if (loadingCommentsFor.has(taskIri)) return;
      setLoadingCommentsFor((prev) => {
        const next = new Set(prev);
        next.add(taskIri);
        return next;
      });
      try {
        const res = await fetch(
          `${ENTRYPOINT}/comments?task=${encodeURIComponent(taskIri)}&itemsPerPage=200`,
          {
            credentials: "include",
            headers: { Accept: "application/ld+json" },
          },
        );
        if (!res.ok) throw new Error("Failed to load comments.");
        const data: { member?: Comment[]; "hydra:member"?: Comment[] } =
          await res.json();
        const list = data.member ?? data["hydra:member"] ?? [];
        setCommentsByTask((prev) => ({ ...prev, [taskIri]: list }));
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to load comments.");
      } finally {
        setLoadingCommentsFor((prev) => {
          const next = new Set(prev);
          next.delete(taskIri);
          return next;
        });
      }
    },
    [commentsByTask, loadingCommentsFor],
  );

  const handleCreateComment = useCallback(
    async (task: Task, body: string) => {
      const trimmed = body.trim();
      if (!trimmed) return;
      const payload: { task: string; body: string } = {
        task: task["@id"],
        body,
      };
      const res = await fetch(`${ENTRYPOINT}/comments`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify(payload),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data["hydra:description"] || data.detail || "Failed to post comment.",
        );
      }
      const created: Comment = await res.json();
      setCommentsByTask((prev) => {
        const existing = prev[task["@id"]] ?? [];
        // The Mercure echo of this POST can race the response and
        // get applied first by handleCommentLiveEvent. Dedup on @id
        // so we don't end up with two copies of the same comment.
        if (existing.some((c) => c["@id"] === created["@id"])) {
          return prev;
        }
        return {
          ...prev,
          [task["@id"]]: [...existing, created],
        };
      });
    },
    [],
  );

  const handleEditComment = useCallback(
    async (comment: Comment, body: string) => {
      const res = await fetch(`${ENTRYPOINT}${comment["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ body }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data["hydra:description"] || data.detail || "Failed to update comment.",
        );
      }
      const updated: Comment = await res.json();
      // Comment doesn't carry its task IRI on the wire (`task` is the bare
      // IRI string serialized at write time, but the read response from
      // PATCH inlines the embedded task). Either way, walk every loaded
      // task list and replace the matching comment.
      setCommentsByTask((prev) => {
        const next: typeof prev = {};
        for (const [taskIri, list] of Object.entries(prev)) {
          if (!list) {
            next[taskIri] = list;
            continue;
          }
          next[taskIri] = list.map((c) =>
            c["@id"] === comment["@id"] ? { ...c, ...updated } : c,
          );
        }
        return next;
      });
    },
    [],
  );

  const handleAttachMedia = useCallback(
    async (task: Task, mediaObjectIri: string) => {
      // Server expects the full attachments IRI list, so derive the next
      // set from the current task entity (already in state) plus the new
      // upload, then PATCH and merge the response back.
      const nextIris = [
        ...task.attachments.map((a) => a["@id"]),
        mediaObjectIri,
      ];
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ attachments: nextIris }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.detail ||
            data["hydra:description"] ||
            "Failed to attach file.",
        );
      }
      const updated = (await res.json()) as Task;
      setTasks((current) =>
        current.map((t) => (t["@id"] === task["@id"] ? updated : t)),
      );
    },
    [],
  );

  const handleDetachMedia = useCallback(
    async (task: Task, attachment: Attachment) => {
      const nextIris = task.attachments
        .filter((a) => a["@id"] !== attachment["@id"])
        .map((a) => a["@id"]);
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ attachments: nextIris }),
      });
      if (!res.ok) {
        throw new Error("Failed to remove attachment.");
      }
      const updated = (await res.json()) as Task;
      setTasks((current) =>
        current.map((t) => (t["@id"] === task["@id"] ? updated : t)),
      );
    },
    [],
  );

  const handleCommentLiveEvent = useCallback(
    (taskIri: string, event: CommentLiveEvent) => {
      // Live deltas merge into the parent's commentsByTask cache. We
      // dedupe on @id so the event from the user's own POST doesn't
      // double-insert when the optimistic local push and the Mercure
      // echo race each other.
      setCommentsByTask((prev) => {
        const list = prev[taskIri];
        if (!list) {
          // Panel hasn't loaded comments yet — nothing to merge into.
          // The lazy fetch will pick up the new comment when expanded.
          return prev;
        }
        if (event.type === "delete") {
          return {
            ...prev,
            [taskIri]: removeComment(list, event.id),
          };
        }
        const incoming = event.comment as unknown as Comment;
        const idx = list.findIndex((c) => c["@id"] === incoming["@id"]);
        if (event.type === "create") {
          if (idx !== -1) return prev;
          return { ...prev, [taskIri]: [...list, incoming] };
        }
        // update
        if (idx === -1) return prev;
        const next = list.slice();
        next[idx] = incoming;
        return { ...prev, [taskIri]: next };
      });
    },
    [],
  );

  const handleDeleteComment = useCallback(async (comment: Comment) => {
    const res = await fetch(`${ENTRYPOINT}${comment["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) {
      throw new Error("Failed to delete comment.");
    }
    // Server CASCADE drops the reply subtree at the DB level but doesn't
    // publish a Mercure delete per descendant — mirror that here so the
    // UI doesn't keep showing orphaned replies until the next reload.
    setCommentsByTask((prev) => {
      const next: typeof prev = {};
      for (const [taskIri, list] of Object.entries(prev)) {
        if (!list) {
          next[taskIri] = list;
          continue;
        }
        next[taskIri] = removeComment(list, comment["@id"]);
      }
      return next;
    });
  }, []);

  const handleDragEnd = async (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;

    const oldIndex = tasks.findIndex((t) => t["@id"] === active.id);
    const newIndex = tasks.findIndex((t) => t["@id"] === over.id);
    if (oldIndex === -1 || newIndex === -1) return;

    const previous = tasks;
    const reordered = arrayMove(tasks, oldIndex, newIndex);
    // Apply optimistically — snappy UX, rolled back below if the server rejects.
    setTasks(reordered);
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}/tasks/reorder`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ order: reordered.map((t) => t["@id"]) }),
      });
      if (!res.ok) {
        throw new Error("Failed to save new order.");
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to save new order.");
    }
  };

  const handleSort = (key: SortKey) => {
    setSort((current) => {
      // Cycle: same column asc → desc → back to manual default.
      if (current.key !== key) return { key, dir: "asc" };
      if (current.dir === "asc") return { key, dir: "desc" };
      return DEFAULT_SORT;
    });
  };

  // Count of overdue tasks across the current assignee scope (so the chip
  // shows e.g. "3 overdue" when "Assigned to me" is active). Computed off the
  // assignee-filtered list, *before* the overdue filter is applied.
  const assigneeFilteredTasks = useMemo(() => {
    if (assigneeFilter === "all") return tasks;
    const targetIri = assigneeFilter === "me" ? currentUserIri : assigneeFilter;
    if (!targetIri) return tasks;
    return tasks.filter((t) => t.assignees.some((a) => a["@id"] === targetIri));
  }, [tasks, assigneeFilter, currentUserIri]);

  const overdueCount = useMemo(
    () =>
      assigneeFilteredTasks.filter(
        (t) => dueDateStatus(t.dueDate, !!t.completedOn) === "overdue",
      ).length,
    [assigneeFilteredTasks],
  );

  // Apply the assignee filter, then the overdue toggle, then sort. "all"
  // leaves tasks intact so the manual-order drag math stays aligned.
  const filteredTasks = useMemo(() => {
    if (!overdueOnly) return assigneeFilteredTasks;
    return assigneeFilteredTasks.filter(
      (t) => dueDateStatus(t.dueDate, !!t.completedOn) === "overdue",
    );
  }, [assigneeFilteredTasks, overdueOnly]);

  const visibleTasks = useMemo(() => {
    if (sort.key === "manual") return filteredTasks;
    const flip = sort.dir === "asc" ? 1 : -1;
    const copy = [...filteredTasks];
    copy.sort((a, b) => {
      switch (sort.key) {
        case "completed":
          // Sort completed flag first — undone tasks sort before done.
          return ((a.completedOn ? 1 : 0) - (b.completedOn ? 1 : 0)) * flip;
        case "title":
          return a.title.localeCompare(b.title) * flip;
        case "due": {
          // Null due dates always sort to the end regardless of direction.
          const aMissing = !a.dueDate;
          const bMissing = !b.dueDate;
          if (aMissing && bMissing) return 0;
          if (aMissing) return 1;
          if (bMissing) return -1;
          return a.dueDate!.localeCompare(b.dueDate!) * flip;
        }
        default:
          return 0;
      }
    });
    return copy;
  }, [filteredTasks, sort]);

  // Reordering is only safe in the "manual" sort *and* when nothing is being
  // filtered out — drag-end math assumes the index lines up with the
  // persisted position.
  const reorderable =
    sort.key === "manual" && assigneeFilter === "all" && !overdueOnly;

  const assignableForTask = useCallback(
    (task: Task): AssigneeOption[] => {
      const self =
        currentUserIri !== null
          ? assignableUsers.find((u) => u["@id"] === currentUserIri)
          : undefined;
      // The current user is always a valid assignee for a task they own —
      // mirrors the server's ValidAssignees rule (owner is always allowed),
      // which matters on a personal / private board where the board lists
      // no other members.
      const ownsTask = task.owner["@id"] === currentUserIri;

      if (!task.board) {
        // Personal task — only the owner is assignable. For an admin viewing
        // someone else's personal task, fall back to its current assignees
        // (already known-valid).
        if (self && ownsTask) return [self];
        return task.assignees;
      }

      const memberIris = boardMembers.get(task.board);
      const base = memberIris
        ? assignableUsers.filter((u) => memberIris.has(u["@id"]))
        : assignableUsers;
      // Guarantee the owner is offered even if the board's member list
      // hasn't caught up (e.g. a private space with no member projection).
      if (self && ownsTask && !base.some((u) => u["@id"] === self["@id"])) {
        return [self, ...base];
      }
      return base;
    },
    [assignableUsers, boardMembers, currentUserIri],
  );

  // Users that have ever been assigned to a task on the page — drives the
  // "specific person" entries in the filter dropdown so we don't list
  // everyone the API would accept.
  const filterableUsers = useMemo(() => {
    const seen = new Map<string, AssigneeOption>();
    for (const t of tasks) {
      for (const a of t.assignees) {
        if (!seen.has(a["@id"])) seen.set(a["@id"], a);
      }
    }
    return Array.from(seen.values());
  }, [tasks]);

  const filterLabel = useMemo(() => {
    if (assigneeFilter === "all") return "All assignees";
    if (assigneeFilter === "me") return "Assigned to me";
    const match = filterableUsers.find((u) => u["@id"] === assigneeFilter);
    return match ? `Assigned to ${displayName(match)}` : "Filtered";
  }, [assigneeFilter, filterableUsers]);

  if (authLoading || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading...</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>{pageTitle} - Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="max-w-7xl mx-auto">
          <div className="flex items-center justify-between mb-6 gap-3 flex-wrap">
            <h1 className="text-2xl font-bold">{pageTitle}</h1>
            <div className="flex items-center gap-2 flex-wrap">
              <div
                className="inline-flex rounded-md border p-0.5"
                data-testid="tasks-view-switcher"
              >
                {(["list", "board", "calendar"] as TaskView[]).map((v) => (
                  <button
                    key={v}
                    type="button"
                    onClick={() => setView(v)}
                    aria-pressed={view === v}
                    className={cn(
                      "rounded px-2.5 py-1 text-sm capitalize transition",
                      view === v
                        ? "bg-accent text-accent-foreground"
                        : "text-muted-foreground hover:text-foreground",
                    )}
                    data-testid={`tasks-view-${v}`}
                  >
                    {v}
                  </button>
                ))}
              </div>
              {/* Overdue chip is always available — it's useful on /my-tasks
                  even though the assignee dropdown is hidden there. The chip
                  is disabled when there's nothing overdue so users still see
                  the zero-state at a glance. */}
              <Button
                variant={overdueOnly ? "default" : "outline"}
                size="sm"
                onClick={() => setOverdueOnly((v) => !v)}
                disabled={overdueCount === 0 && !overdueOnly}
                aria-pressed={overdueOnly}
                data-testid="overdue-filter-toggle"
              >
                <AlertTriangle className="h-3.5 w-3.5 mr-1" />
                Overdue
                <span
                  className={cn(
                    "ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold",
                    overdueOnly
                      ? "bg-primary-foreground/20 text-primary-foreground"
                      : overdueCount > 0
                        ? "bg-destructive text-destructive-foreground"
                        : "bg-muted text-muted-foreground",
                  )}
                  data-testid="overdue-filter-count"
                >
                  {overdueCount}
                </span>
              </Button>
              {/* The assignee dropdown is redundant on /my-tasks (everything
                  shown is already filtered to the current user). */}
              {!isMyTasksPage && (
              <>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    data-testid="assignee-filter-trigger"
                  >
                    <Filter className="h-3.5 w-3.5 mr-1" />
                    {filterLabel}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                  <DropdownMenuLabel>Filter by assignee</DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  <DropdownMenuCheckboxItem
                    checked={assigneeFilter === "all"}
                    onCheckedChange={() => setAssigneeFilter("all")}
                  >
                    All assignees
                  </DropdownMenuCheckboxItem>
                  <DropdownMenuCheckboxItem
                    checked={assigneeFilter === "me"}
                    onCheckedChange={() => setAssigneeFilter("me")}
                    disabled={!currentUserIri}
                  >
                    Assigned to me
                  </DropdownMenuCheckboxItem>
                  {filterableUsers.length > 0 && <DropdownMenuSeparator />}
                  {filterableUsers.map((u) => (
                    <DropdownMenuCheckboxItem
                      key={u["@id"]}
                      checked={assigneeFilter === u["@id"]}
                      onCheckedChange={() => setAssigneeFilter(u["@id"])}
                    >
                      <span className="flex items-center gap-2 truncate">
                        <UserAvatar user={u} size="sm" className="h-5 w-5" />
                        <span className="truncate">{displayName(u)}</span>
                      </span>
                    </DropdownMenuCheckboxItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
              {assigneeFilter !== "all" && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setAssigneeFilter("all")}
                  aria-label="Clear assignee filter"
                  data-testid="assignee-filter-clear"
                >
                  <X className="h-3.5 w-3.5" />
                </Button>
              )}
              </>
              )}
            </div>
          </div>

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {view === "list" && !reorderable && (
            <p
              className="mb-2 text-xs text-muted-foreground"
              data-testid="reorder-disabled-hint"
            >
              Drag-to-reorder is paused while the list is filtered or sorted by a
              column. Clear the filter and reset the column header to return to
              your custom order.
            </p>
          )}

          {isLoading ? (
            // Same Card the table lands in, so the surface is already there
            // when the rows arrive. Row height measured off the real list.
            <Card>
              <CardContent className="p-4">
                <SkeletonList rows={6} label="Loading tasks" itemClassName="h-14" />
              </CardContent>
            </Card>
          ) : view === "calendar" ? (
            activeSpace ? (
              <CalendarView
                spaceIri={activeSpace["@id"]}
                onOpen={openTaskById}
                refreshSignal={calendarRefresh}
                assignableUsers={assignableUsers}
              />
            ) : (
              <p className="text-sm text-muted-foreground">Loading space…</p>
            )
          ) : view === "board" ? (
            <TasksBoardView
              tasks={filteredTasks}
              boardTitles={boardTitles}
              onOpen={openTaskDetail}
            />
          ) : (
            <Card>
              <CardContent className="p-0">
                <DndContext
                  sensors={sensors}
                  collisionDetection={closestCenter}
                  onDragEnd={handleDragEnd}
                >
                  <SortableContext
                    items={visibleTasks.map((t) => t["@id"])}
                    strategy={verticalListSortingStrategy}
                  >
                    <Table data-testid="task-list">
                      <TableHeader>
                        <TableRow>
                          <TableHead className="w-8" />
                          <SortableHeader
                            label="Done"
                            sortKey="completed"
                            active={sort}
                            onSort={handleSort}
                            className="w-16"
                          />
                          <SortableHeader
                            label="Title"
                            sortKey="title"
                            active={sort}
                            onSort={handleSort}
                          />
                          <SortableHeader
                            label="Due"
                            sortKey="due"
                            active={sort}
                            onSort={handleSort}
                            className="w-36"
                          />
                          <TableHead>Tags</TableHead>
                          <TableHead>Assignees</TableHead>
                          <TableHead className="w-20 text-right">Actions</TableHead>
                        </TableRow>
                      </TableHeader>
                      <NewTaskRow
                        allTags={allTags}
                        assignableUsers={assignableUsers}
                        onCreate={handleCreate}
                        isCreating={isSubmitting}
                        currentUserIri={currentUserIri}
                        autoAssignSelf={isMyTasksPage}
                      />
                      {visibleTasks.map((task) => (
                        <TaskRow
                          key={task["@id"]}
                          task={task}
                          allTags={allTags}
                          assignableUsers={assignableForTask(task)}
                          reorderable={reorderable}
                          currentUserIri={currentUserIri}
                          comments={commentsByTask[task["@id"]]}
                          commentsLoading={loadingCommentsFor.has(task["@id"])}
                          onToggle={handleToggle}
                          onDelete={handleDelete}
                          onOpenDetail={openTaskDetail}
                          onTagsChange={handleTagsChange}
                          onTitleChange={handleTitleChange}
                          onDescriptionChange={handleDescriptionChange}
                          onDueDateChange={handleDueDateChange}
                          onRecurrenceChange={handleRecurrenceChange}
                          onRemindersChange={handleRemindersChange}
                          onAssigneesChange={handleAssigneesChange}
                          onAssigneeAvatarClick={(assignee) =>
                            setAssigneeFilter(assignee["@id"])
                          }
                          onLoadComments={handleLoadComments}
                          onCreateComment={handleCreateComment}
                          onEditComment={handleEditComment}
                          onDeleteComment={handleDeleteComment}
                          onAttachMedia={handleAttachMedia}
                          onDetachMedia={handleDetachMedia}
                          onCommentLiveEvent={handleCommentLiveEvent}
                        />
                      ))}
                    </Table>
                  </SortableContext>
                </DndContext>
              </CardContent>
            </Card>
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
        onTaskChanged={(updated) => {
          setTasks((current) =>
            current.map((t) =>
              t["@id"] === updated["@id"]
                ? {
                    ...t,
                    title: updated.title,
                    description: updated.description,
                    completedOn: updated.completedOn,
                    dueDate: updated.dueDate,
                    tags: updated.tags,
                    assignees: updated.assignees,
                    attachments: updated.attachments,
                  }
                : t,
            ),
          );
          setCalendarRefresh((k) => k + 1);
        }}
        onTaskDeleted={(iri) => {
          setTasks((current) => current.filter((t) => t["@id"] !== iri));
          setCalendarRefresh((k) => k + 1);
        }}
      />
    </>
  );
};

export default Tasks;
