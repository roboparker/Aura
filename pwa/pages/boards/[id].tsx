import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type RefObject,
} from "react";
import { Check, ChevronDown, ChevronRight, Copy, Lock, MoreHorizontal, PanelRight, Plus, Rows3, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { randomPaletteColor } from "@/lib/avatarPalette";
import ActivityPanel from "@/components/activity/ActivityPanel";
import TaskBoard from "@/components/boards/TaskBoard";
import CalendarView from "@/components/calendar/CalendarView";
import FilterMultiSelect from "@/components/common/FilterMultiSelect";
import { displayName } from "@/lib/userDisplay";
import TaskTableColumns from "@/components/boards/TaskTableColumns";
import { computeColumnWidths } from "@/components/boards/columnWidths";
import ColumnHeaderMenu from "@/components/boards/ColumnHeaderMenu";
import {
  applyView,
  buildColumns,
  orderColumns,
  type FilterMap,
  type FilterValue,
  type ListColumn,
  type SortState,
} from "@/components/boards/listColumns";
import { useBoardListView } from "@/lib/useBoardListView";
import {
  DndContext,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type CollisionDetection,
  type DragEndEvent,
  type DragOverEvent,
  type DragStartEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  useSortable,
  horizontalListSortingStrategy,
  verticalListSortingStrategy,
  arrayMove,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { GripVertical } from "lucide-react";
import CustomFieldFooterRow from "@/components/custom-fields/CustomFieldFooterRow";
import BoardCustomFieldPicker from "@/components/custom-fields/BoardCustomFieldPicker";
import CustomFieldSheet from "@/components/custom-fields/CustomFieldSheet";
import AddFieldMenu from "@/components/boards/AddFieldMenu";
import { CustomFieldValueEditor } from "@/components/tasks/value-editors";
import DueDateCell from "@/components/tasks/DueDateCell";
import TagsCombobox, { type TagOption } from "@/components/tasks/TagsCombobox";
import AssignMenu from "@/components/tasks/AssignMenu";
import AssigneesCombobox, {
  type AssigneeOption,
} from "@/components/tasks/AssigneesCombobox";
import TaskDetailDrawer from "@/components/tasks/TaskDetailDrawer";
import BoardTimeline from "@/components/boards/BoardTimeline";
import type {
  CustomFieldDefinition,
  CustomFieldKind,
  CustomFieldSubtype,
} from "@/components/custom-fields/types";
import {
  isGlobalDefinition,
  showsOnSurface,
  visibilitySurfaces,
  TIMELINE_START_SYSTEM_KEY,
} from "@/components/custom-fields/types";
import {
  makeValuePair,
  valuePairDefinitionIri,
  type CustomFieldValuePair,
} from "@/components/tasks/CustomFieldValueList";
import {
  dueDateStatus,
  type Reminder,
  type RecurrenceRule,
} from "@/components/tasks/taskHelpers";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Switch } from "@/components/ui/switch";
import ConfirmDialog from "@/components/common/ConfirmDialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { cn } from "@/lib/utils";

interface Member {
  "@id": string;
  id: string;
  email: string;
}

interface SpaceRef {
  "@id": string;
  id: string;
  name: string;
  isPersonal: boolean;
}

interface Board {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  owner: Member;
  members: Member[];
  space: string | SpaceRef;
  /**
   * Timeline (#timeline): when true, the Gantt tab is active and the canonical
   * global "Start date" field is attached to this board (the server keeps it so).
   */
  timelineEnabled?: boolean;
}

interface BoardTask {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  completedOn: string | null;
  dueDate: string | null;
  position: number;
  tags: TagOption[];
  assignees: AssigneeOption[];
  recurrenceRule: RecurrenceRule | null;
  reminders: Reminder[] | null;
  customFieldValues: CustomFieldValuePair[];
  /** Board section IRI, or null = the default "In progress" group. */
  section: string | null;
}

interface TaskSection {
  "@id": string;
  id: string;
  title: string;
  position: number;
}

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

const membersOf = <T,>(c: Collection<T>): T[] =>
  c.member ?? c["hydra:member"] ?? [];

const boardSpaceIri = (board: Board): string =>
  typeof board.space === "string" ? board.space : board.space["@id"];

const isEmptyFieldValue = (value: unknown): boolean =>
  value === null ||
  value === undefined ||
  value === "" ||
  (Array.isArray(value) && value.length === 0);

// The implicit group for tasks with no section (Task.section === null).
const DEFAULT_SECTION_KEY = "__default__";
const DEFAULT_SECTION_LABEL = "In progress";

/** Draft payload for the inline add-task row (IRIs for the relations). */
interface NewTaskDraft {
  title: string;
  dueDate: string | null;
  assignees: string[];
  tags: string[];
}

const BoardDetail = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { spaces } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const boardId = typeof id === "string" ? id : null;
  const currentUserIri = user ? `/users/${user.id}` : null;

  const [board, setBoard] = useState<Board | null>(null);
  const [tasks, setTasks] = useState<BoardTask[]>([]);
  // Bumped to nudge the Calendar tab to refetch after drawer/list edits.
  const [calendarRefresh, setCalendarRefresh] = useState(0);
  // Board view filters (assignee / tags).
  const [boardAssignees, setBoardAssignees] = useState<Set<string>>(new Set());
  const [boardTags, setBoardTags] = useState<Set<string>>(new Set());
  const [definitions, setDefinitions] = useState<CustomFieldDefinition[]>([]);
  const [sections, setSections] = useState<TaskSection[]>([]);
  const [assignableUsers, setAssignableUsers] = useState<AssigneeOption[]>([]);
  const [allTags, setAllTags] = useState<TagOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Which detail tab is active (list | board | activity | settings) —
  // controlled so the header's "New task" button shows on the List tab only.
  const [activeTab, setActiveTab] = useState("list");
  const [confirmDeleteBoardOpen, setConfirmDeleteBoardOpen] =
    useState(false);

  // Editable board name + description (Settings tab → Board details).
  const [nameDraft, setNameDraft] = useState("");
  const [descDraft, setDescDraft] = useState("");
  const [isSavingDetails, setIsSavingDetails] = useState(false);
  const [detailsMessage, setDetailsMessage] = useState<{
    text: string;
    kind: "success" | "error";
  } | null>(null);

  // Seed the editable name/description drafts when a (different) board loads.
  // Keyed on the id so a background reload after save doesn't clobber edits.
  useEffect(() => {
    if (board) {
      setNameDraft(board.title);
      setDescDraft(board.description ?? "");
      setDetailsMessage(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [board?.id]);

  const detailsDirty =
    !!board &&
    (nameDraft.trim() !== board.title ||
      (descDraft.trim() || "") !== (board.description ?? ""));

  // Each section shows a collapsed "+ Add task" trigger that expands into a
  // full draft row (managed locally by AddTaskRow). The top "New task" button
  // clicks the default group's trigger to open + focus it.
  const defaultAddTriggerRef = useRef<HTMLButtonElement | null>(null);
  const focusDefaultAddRow = () =>
    requestAnimationFrame(() => defaultAddTriggerRef.current?.click());

  // The board title + tabs sticky bar has a variable height, so we measure it
  // and offset the column header to stick directly beneath it (navbar = 56px).
  const NAVBAR_H = 56;
  const stickyHeaderRef = useRef<HTMLDivElement | null>(null);
  const [columnHeaderTop, setColumnHeaderTop] = useState(NAVBAR_H);
  useEffect(() => {
    const el = stickyHeaderRef.current;
    if (!el) return;
    const update = () => setColumnHeaderTop(NAVBAR_H + el.offsetHeight);
    update();
    const ro = new ResizeObserver(update);
    ro.observe(el);
    return () => ro.disconnect();
  });
  // Bumped whenever the task set changes so the aggregate footer re-fetches.
  const [footerKey, setFooterKey] = useState(0);

  // Move / copy to space (#182).
  const [moveTargetIri, setMoveTargetIri] = useState("");
  const [isMoving, setIsMoving] = useState(false);
  const [isCopying, setIsCopying] = useState(false);
  const [copyIncludeTasks, setCopyIncludeTasks] = useState(false);
  const [moveMessage, setMoveMessage] = useState<{
    text: string;
    kind: "success" | "error";
  } | null>(null);

  // Deep-linkable task drawer (?task={id}).
  const activeTaskId =
    typeof router.query.task === "string" ? router.query.task : null;
  const openTaskDetail = useCallback(
    (task: BoardTask) => {
      if (!boardId) return;
      // Interpolate `id` explicitly — relying on router.query alone throws in
      // Next 16 if the dynamic param is momentarily absent during a shallow
      // route transition.
      void router.push(
        {
          pathname: "/boards/[id]",
          query: { ...router.query, id: boardId, task: task.id },
        },
        undefined,
        { shallow: true },
      );
    },
    [router, boardId],
  );
  // Id-based opener for the calendar (which hands back a task id, not a Task).
  const openTaskById = useCallback(
    (taskId: string) => {
      if (!boardId) return;
      void router.push(
        {
          pathname: "/boards/[id]",
          query: { ...router.query, id: boardId, task: taskId },
        },
        undefined,
        { shallow: true },
      );
    },
    [router, boardId],
  );
  const closeTaskDetail = useCallback(
    (open: boolean) => {
      if (open || !boardId) return;
      const query = { ...router.query };
      delete query.task;
      query.id = boardId;
      void router.push({ pathname: "/boards/[id]", query }, undefined, {
        shallow: true,
      });
    },
    [router, boardId],
  );

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    if (!boardId) return;
    setError(null);
    setIsLoading(true);
    try {
      const init = {
        credentials: "include" as const,
        headers: { Accept: "application/ld+json" },
      };
      const boardRes = await fetch(
        `${ENTRYPOINT}/boards/${encodeURIComponent(boardId)}`,
        init,
      );
      if (boardRes.status === 404 || boardRes.status === 403) {
        setNotFound(true);
        return;
      }
      if (!boardRes.ok) throw new Error("Failed to load board.");
      const boardData: Board = await boardRes.json();
      setBoard(boardData);

      const boardIri = boardData["@id"];
      const [tasksRes, defsRes, globalDefsRes, sectionsRes, usersRes, tagsRes] =
        await Promise.all([
          fetch(`${ENTRYPOINT}/tasks?board=${encodeURIComponent(boardIri)}`, init),
          fetch(
            `${ENTRYPOINT}/custom_field_definitions?boards=${encodeURIComponent(boardIri)}`,
            init,
          ),
          fetch(
            `${ENTRYPOINT}/global_custom_field_definitions?boards=${encodeURIComponent(boardIri)}`,
            init,
          ),
          fetch(
            `${ENTRYPOINT}/task_sections?board=${encodeURIComponent(boardIri)}`,
            init,
          ),
          fetch(`${ENTRYPOINT}/me/assignable-users`, init),
          fetch(
            `${ENTRYPOINT}/tags?space=${encodeURIComponent(boardSpaceIri(boardData))}`,
            init,
          ),
        ]);
      if (!tasksRes.ok) throw new Error("Failed to load tasks.");
      setTasks(membersOf<BoardTask>(await tasksRes.json()));
      // A board's effective field set is the union of its space fields and
      // the instance-wide global fields it opts into (#global-custom-fields).
      const spaceDefs = defsRes.ok
        ? membersOf<CustomFieldDefinition>(await defsRes.json())
        : [];
      const globalDefs = globalDefsRes.ok
        ? membersOf<CustomFieldDefinition>(await globalDefsRes.json())
        : [];
      setDefinitions(
        [...spaceDefs, ...globalDefs].sort((a, b) => a.position - b.position),
      );
      if (sectionsRes.ok) {
        setSections(
          membersOf<TaskSection>(await sectionsRes.json()).sort(
            (a, b) => a.position - b.position,
          ),
        );
      }
      if (usersRes.ok) setAssignableUsers(membersOf<AssigneeOption>(await usersRes.json()));
      if (tagsRes.ok) setAllTags(membersOf<TagOption>(await tagsRes.json()));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load board.");
    } finally {
      setIsLoading(false);
    }
  }, [boardId]);

  useEffect(() => {
    if (isAuthenticated && boardId) void load();
  }, [isAuthenticated, boardId, load]);

  // Any change to the task set (inline edit, toggle, create, drawer) re-fetches
  // the aggregate footer so its sums/averages stay live without a reload.
  useEffect(() => {
    setFooterKey((k) => k + 1);
  }, [tasks]);

  // Re-sync the task-column definitions after the Settings-tab manager mutates
  // them, so a created/edited/reordered/deleted field reflects without reload.
  const reloadDefinitions = useCallback(async () => {
    if (!board) return;
    try {
      const iri = encodeURIComponent(board["@id"]);
      const [spaceRes, globalRes] = await Promise.all([
        fetch(`${ENTRYPOINT}/custom_field_definitions?boards=${iri}`, {
          credentials: "include",
        }),
        fetch(`${ENTRYPOINT}/global_custom_field_definitions?boards=${iri}`, {
          credentials: "include",
        }),
      ]);
      const spaceDefs = spaceRes.ok
        ? membersOf<CustomFieldDefinition>(await spaceRes.json())
        : [];
      const globalDefs = globalRes.ok
        ? membersOf<CustomFieldDefinition>(await globalRes.json())
        : [];
      if (spaceRes.ok || globalRes.ok) {
        setDefinitions(
          [...spaceDefs, ...globalDefs].sort((a, b) => a.position - b.position),
        );
      }
    } catch {
      /* keep the current columns on a transient failure */
    }
  }, [board]);

  // Attach a field to this board (per-board selection M2M). Space and
  // global fields live in separate join tables, so PATCH the matching key
  // with the full current set of that source plus the new IRI.
  const attachFieldToBoard = useCallback(
    async (defIri: string) => {
      if (!board) return;
      if (definitions.some((d) => d["@id"] === defIri)) return;
      const global = isGlobalDefinition(defIri);
      const currentOfSource = definitions
        .filter((d) => isGlobalDefinition(d) === global)
        .map((d) => d["@id"]);
      const key = global
        ? "globalCustomFieldDefinitions"
        : "customFieldDefinitions";
      try {
        await fetch(`${ENTRYPOINT}${board["@id"]}`, {
          method: "PATCH",
          credentials: "include",
          headers: { "Content-Type": "application/merge-patch+json" },
          body: JSON.stringify({ [key]: [...currentOfSource, defIri] }),
        });
      } catch {
        /* transient — the field just won't show until retried */
      }
    },
    [board, definitions],
  );

  // Fields on the board but hidden from the list view — offered in the
  // add-column menu to re-show.
  const hiddenListFields = useMemo(
    () => definitions.filter((d) => !showsOnSurface(d.visibility, "list")),
    [definitions],
  );

  // Reveal a hidden field in the list view by adding `list` to its per-board
  // surface set.
  const enableListField = useCallback(
    async (def: CustomFieldDefinition) => {
      if (!boardId) return;
      const surfaces = [
        ...new Set([...visibilitySurfaces(def.visibility), "list"]),
      ].join(",");
      const base = isGlobalDefinition(def)
        ? "global_custom_field_definitions"
        : "custom_field_definitions";
      try {
        const res = await fetch(
          `${ENTRYPOINT}/boards/${encodeURIComponent(boardId)}/${base}/${encodeURIComponent(def.id)}/visibility`,
          {
            method: "PUT",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ visibility: surfaces }),
          },
        );
        if (res.ok) void reloadDefinitions();
      } catch {
        /* transient — leave the field hidden */
      }
    },
    [boardId, reloadDefinitions],
  );

  // Create a tag from free text typed into a tags field (Enter / comma).
  const createTag = useCallback(
    async (title: string): Promise<TagOption | null> => {
      if (!board) return null;
      try {
        const res = await fetch(`${ENTRYPOINT}/tags`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/ld+json" },
          // Tags are space-scoped: create it in this board's space. Give
          // it a random palette color so inline-created tags aren't all grey.
          body: JSON.stringify({
            title,
            space: boardSpaceIri(board),
            color: randomPaletteColor(),
          }),
        });
        if (!res.ok) return null;
        const created = (await res.json()) as TagOption;
        setAllTags((prev) =>
          prev.some((t) => t["@id"] === created["@id"])
            ? prev
            : [...prev, created],
        );
        return created;
      } catch {
        return null;
      }
    },
    [board],
  );

  // Drives the "create a new field of type X" modal opened from the add menu.
  const [newFieldType, setNewFieldType] = useState<{
    kind: CustomFieldKind;
    subtype: CustomFieldSubtype;
  } | null>(null);
  // The custom-field definition being edited from a column header menu.
  const [editFieldDef, setEditFieldDef] = useState<CustomFieldDefinition | null>(
    null,
  );

  // Generic single-task PATCH used by every inline row editor.
  const patchTask = useCallback(
    async (task: BoardTask, body: Record<string, unknown>) => {
      setError(null);
      try {
        const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
          method: "PATCH",
          credentials: "include",
          headers: { "Content-Type": "application/merge-patch+json" },
          body: JSON.stringify(body),
        });
        if (!res.ok) throw new Error("Failed to update task.");
        const updated: BoardTask = await res.json();
        setTasks((prev) =>
          prev.map((t) => (t["@id"] === task["@id"] ? updated : t)),
        );
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to update task.");
      }
    },
    [],
  );

  // Replace one definition's value and PATCH the whole array (dropping empties,
  // in definition order) like the drawer's CustomFieldValueList does.
  const handleCustomFieldChange = useCallback(
    (task: BoardTask, defIri: string, value: unknown) => {
      const next = definitions
        .map((def) => {
          const existing = task.customFieldValues.find(
            (v) => valuePairDefinitionIri(v) === def["@id"],
          );
          return makeValuePair(
            def,
            def["@id"] === defIri ? value : existing?.value,
          );
        })
        .filter((p) => !isEmptyFieldValue(p.value));
      void patchTask(task, { customFieldValues: next });
    },
    [definitions, patchTask],
  );

  // Definitions surfaced per view. The drawer always shows every field; list /
  // board / calendar each honour the field's visibility surface set.
  const listDefinitions = useMemo(
    () => definitions.filter((d) => showsOnSurface(d.visibility, "list")),
    [definitions],
  );
  const boardDefinitions = useMemo(
    () => definitions.filter((d) => showsOnSurface(d.visibility, "board")),
    [definitions],
  );

  // Timeline (#timeline): the canonical global "Start date" field drives bar
  // starts. It's attached to the board (so it's in `definitions`) whenever the
  // feature is on; resolve its IRI for the Gantt, or null when off.
  const timelineStartFieldIri = useMemo(() => {
    if (!board?.timelineEnabled) return null;
    return (
      definitions.find((d) => d.systemKey === TIMELINE_START_SYSTEM_KEY)?.["@id"] ?? null
    );
  }, [board?.timelineEnabled, definitions]);

  // The assignee picker must only offer users who can actually be assigned
  // to this board's tasks — its space members — not the caller's whole
  // assignable universe (which spans every space they're in). On a private
  // board that narrows the list to just the user. `assignableUsers` carries
  // the rich avatar/colour shape the picker needs, so we filter it by the
  // board's member IRIs rather than using the bare `board.members`.
  const boardAssignableUsers = useMemo(() => {
    if (!board) return assignableUsers;
    const memberIris = new Set(board.members.map((m) => m["@id"]));
    return assignableUsers.filter((u) => memberIris.has(u["@id"]));
  }, [assignableUsers, board]);

  // Board filter option lists (alphabetical).
  const byName = (a: [string, string], b: [string, string]) =>
    a[1].localeCompare(b[1], undefined, { sensitivity: "base" });
  const boardAssigneeOptions = useMemo<[string, string][]>(
    () =>
      boardAssignableUsers
        .map((u): [string, string] => [u["@id"], displayName(u)])
        .sort(byName),
    [boardAssignableUsers],
  );
  const boardTagOptions = useMemo<[string, string][]>(
    () => allTags.map((t): [string, string] => [t["@id"], t.title]).sort(byName),
    [allTags],
  );

  // Per-user, per-board list-view state (column order + sort + filters),
  // persisted in localStorage. Applied within each section by SectionBlock.
  const listView = useBoardListView(boardId);
  const columns = useMemo(
    () => orderColumns(buildColumns(listDefinitions), listView.order),
    [listDefinitions, listView.order],
  );
  const handleColumnReorder = useCallback(
    (activeKey: string, overKey: string) => {
      const keys = columns.map((c) => c.key);
      const from = keys.indexOf(activeKey);
      const to = keys.indexOf(overKey);
      if (from === -1 || to === -1 || from === to) return;
      listView.setOrder(arrayMove(keys, from, to));
    },
    [columns, listView],
  );
  // checkbox + each data column + trailing actions.
  const fullColSpan = columns.length + 2;
  const columnKeys = columns.map((c) => c.key);

  // Content-aware column widths, shared across the list's separate tables so
  // they stay aligned. We track the list container's width (it remounts with
  // the List tab, so a callback ref wires the observer on mount) and feed it to
  // the calculator, which grows columns to fit their content up to that width.
  const [listWidth, setListWidth] = useState(0);
  const listResizeObserver = useRef<ResizeObserver | null>(null);
  const listContainerRef = useCallback((node: HTMLDivElement | null) => {
    listResizeObserver.current?.disconnect();
    if (node && typeof ResizeObserver !== "undefined") {
      const ro = new ResizeObserver((entries) => {
        setListWidth(entries[0]?.contentRect.width ?? 0);
      });
      ro.observe(node);
      listResizeObserver.current = ro;
      setListWidth(node.clientWidth);
    }
  }, []);
  const colWidths = useMemo(
    () => computeColumnWidths(columns, tasks, listWidth),
    [columns, tasks, listWidth],
  );
  const listSensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
  );
  const onColumnDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (over && active.id !== over.id) {
      handleColumnReorder(String(active.id), String(over.id));
    }
  };

  const toggleComplete = async (task: BoardTask) => {
    const completedOn = task.completedOn ? null : new Date().toISOString();
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ completedOn }),
      });
      if (!res.ok) throw new Error("Failed to update task.");
      const updated: BoardTask = await res.json();
      setTasks((prev) => prev.map((t) => (t["@id"] === task["@id"] ? updated : t)));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update task.");
    }
  };

  // Row action menu: duplicate a task (copy its fields into a new task in the
  // same section) and delete it.
  const duplicateTask = useCallback(
    async (task: BoardTask) => {
      if (!board) return;
      setError(null);
      try {
        const res = await fetch(`${ENTRYPOINT}/tasks`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/ld+json" },
          body: JSON.stringify({
            title: `${task.title} (copy)`,
            board: board["@id"],
            ...(task.section ? { section: task.section } : {}),
            ...(task.dueDate ? { dueDate: task.dueDate } : {}),
            ...(task.tags.length ? { tags: task.tags.map((t) => t["@id"]) } : {}),
            ...(task.assignees.length
              ? { assignees: task.assignees.map((a) => a["@id"]) }
              : {}),
            ...(task.recurrenceRule ? { recurrenceRule: task.recurrenceRule } : {}),
            ...(task.reminders?.length ? { reminders: task.reminders } : {}),
            ...(task.customFieldValues.length
              ? { customFieldValues: task.customFieldValues }
              : {}),
          }),
        });
        if (!res.ok) throw new Error("Failed to duplicate task.");
        const created: BoardTask = await res.json();
        setTasks((prev) => [...prev, created]);
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to duplicate task.");
      }
    },
    [board],
  );

  const deleteTask = useCallback(async (task: BoardTask) => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok && res.status !== 204) throw new Error("Failed to delete task.");
      setTasks((prev) => prev.filter((t) => t["@id"] !== task["@id"]));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete task.");
    }
  }, []);

  // Inline add from a section's expandable add row: create with the drafted
  // title + due / assignees / tags. Returns true on success so the add row
  // can clear + refocus for rapid entry.
  const createTaskInSection = useCallback(
    async (sectionIri: string | null, draft: NewTaskDraft): Promise<boolean> => {
      const title = draft.title.trim();
      if (!board || !title) return false;
      setError(null);
      try {
        const res = await fetch(`${ENTRYPOINT}/tasks`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/ld+json" },
          body: JSON.stringify({
            title,
            board: board["@id"],
            ...(sectionIri ? { section: sectionIri } : {}),
            ...(draft.dueDate ? { dueDate: draft.dueDate } : {}),
            ...(draft.assignees.length ? { assignees: draft.assignees } : {}),
            ...(draft.tags.length ? { tags: draft.tags } : {}),
          }),
        });
        if (!res.ok) {
          const data = await res.json().catch(() => ({}));
          throw new Error(
            data.description ||
              data.detail ||
              data["hydra:description"] ||
              "Failed to create task.",
          );
        }
        const created: BoardTask = await res.json();
        setTasks((prev) => [...prev, created]);
        return true;
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to create task.");
        return false;
      }
    },
    [board],
  );

  const createSection = async (title = "New section") => {
    if (!board) return;
    const trimmed = title.trim() || "New section";
    try {
      const res = await fetch(`${ENTRYPOINT}/task_sections`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          board: board["@id"],
          title: trimmed,
          position: sections.length,
        }),
      });
      if (!res.ok) throw new Error("Failed to add section.");
      const created: TaskSection = await res.json();
      setSections((prev) => [...prev, created]);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to add section.");
    }
  };

  const renameSection = async (section: TaskSection, title: string) => {
    const trimmed = title.trim();
    if (trimmed === "" || trimmed === section.title) return;
    setSections((prev) =>
      prev.map((s) =>
        s["@id"] === section["@id"] ? { ...s, title: trimmed } : s,
      ),
    );
    try {
      const res = await fetch(`${ENTRYPOINT}${section["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ title: trimmed }),
      });
      if (!res.ok) throw new Error("Failed to rename section.");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to rename section.");
    }
  };

  const deleteSection = async (section: TaskSection) => {
    try {
      const res = await fetch(`${ENTRYPOINT}${section["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok && res.status !== 204) {
        throw new Error("Failed to delete section.");
      }
      setSections((prev) => prev.filter((s) => s["@id"] !== section["@id"]));
      // Its tasks fall back to the default group (server SET NULL).
      setTasks((prev) =>
        prev.map((t) =>
          t.section === section["@id"] ? { ...t, section: null } : t,
        ),
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete section.");
    }
  };

  // Move a task to another section (or the default group when null) — used by
  // the board's drag-between-columns.
  const moveTaskToSection = (taskIri: string, sectionIri: string | null) => {
    const task = tasks.find((t) => t["@id"] === taskIri);
    if (!task || task.section === sectionIri) return;
    setTasks((prev) =>
      prev.map((t) => (t["@id"] === taskIri ? { ...t, section: sectionIri } : t)),
    );
    void patchTask(task, { section: sectionIri });
  };

  const handleSaveDetails = async () => {
    if (!board || !nameDraft.trim()) return;
    setIsSavingDetails(true);
    setDetailsMessage(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/boards/${encodeURIComponent(board.id)}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          title: nameDraft.trim(),
          description: descDraft.trim() || null,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(
          data.detail || data.error || data["hydra:description"] || "Failed to save changes.",
        );
      }
      setBoard((prev) => (prev ? { ...prev, ...data } : prev));
      setDetailsMessage({ text: "Saved.", kind: "success" });
    } catch (err) {
      setDetailsMessage({
        text: err instanceof Error ? err.message : "Failed to save changes.",
        kind: "error",
      });
    } finally {
      setIsSavingDetails(false);
    }
  };

  // Timeline (#timeline): flip the feature on/off. Enabling attaches the
  // canonical global "Start date" field server-side (BoardTimelineProcessor),
  // so a reload of the board's field set is needed to pick it up.
  const handleSetTimelineEnabled = useCallback(
    async (enabled: boolean) => {
      if (!board) return;
      try {
        const res = await fetch(`${ENTRYPOINT}/boards/${encodeURIComponent(board.id)}`, {
          method: "PATCH",
          credentials: "include",
          headers: { "Content-Type": "application/merge-patch+json" },
          body: JSON.stringify({ timelineEnabled: enabled }),
        });
        if (!res.ok) throw new Error("Failed to update Timeline.");
        const data = await res.json();
        setBoard((prev) => (prev ? { ...prev, ...data } : prev));
        // The attached global-field set changed — refresh definitions so the
        // Start date field appears (on enable) for the timeline + task columns.
        await load();
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to update Timeline.");
      }
    },
    [board, load],
  );

  const handleMove = async () => {
    if (!board || !moveTargetIri) return;
    setIsMoving(true);
    setMoveMessage(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/boards/${encodeURIComponent(board.id)}/move`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ space: moveTargetIri }),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(
          data.detail || data.error || data["hydra:description"] || "Failed to move board.",
        );
      }
      const target = spaces.find((s) => s["@id"] === moveTargetIri);
      setMoveMessage({
        text: data.moved
          ? `Moved to "${target?.name ?? "the selected space"}".`
          : "Already in that space.",
        kind: "success",
      });
      setMoveTargetIri("");
      await load();
    } catch (err) {
      setMoveMessage({
        text: err instanceof Error ? err.message : "Failed to move board.",
        kind: "error",
      });
    } finally {
      setIsMoving(false);
    }
  };

  const handleCopy = async () => {
    if (!board) return;
    setIsCopying(true);
    setMoveMessage(null);
    try {
      const body: { space?: string; includeTasks?: boolean } = {};
      if (moveTargetIri) body.space = moveTargetIri;
      if (copyIncludeTasks) body.includeTasks = true;
      const res = await fetch(
        `${ENTRYPOINT}/boards/${encodeURIComponent(board.id)}/copy`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(body),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(
          data.detail || data.error || data["hydra:description"] || "Failed to copy board.",
        );
      }
      if (data.id) await router.push(`/boards/${data.id}`);
    } catch (err) {
      setMoveMessage({
        text: err instanceof Error ? err.message : "Failed to copy board.",
        kind: "error",
      });
    } finally {
      setIsCopying(false);
    }
  };

  const handleDeleteBoard = async () => {
    if (!board) return;
    const res = await fetch(
      `${ENTRYPOINT}/boards/${encodeURIComponent(board.id)}`,
      { method: "DELETE", credentials: "include" },
    );
    if (!res.ok && res.status !== 204) {
      throw new Error("Failed to delete board.");
    }
    await router.push("/boards");
  };

  // Group tasks by board section. The default "In progress" group (null
  // section) always leads; user sections follow in position order. Empty
  // sections still render so tasks can be added/moved into them.
  const sectionGroups = useMemo(() => {
    const ordered = [...tasks].sort((a, b) => a.position - b.position);
    const bySection = new Map<string, BoardTask[]>();
    for (const task of ordered) {
      const key = task.section ?? DEFAULT_SECTION_KEY;
      const list = bySection.get(key) ?? [];
      list.push(task);
      bySection.set(key, list);
    }
    const groups: {
      key: string;
      section: TaskSection | null;
      tasks: BoardTask[];
    }[] = [
      {
        key: DEFAULT_SECTION_KEY,
        section: null,
        tasks: bySection.get(DEFAULT_SECTION_KEY) ?? [],
      },
    ];
    for (const section of [...sections].sort((a, b) => a.position - b.position)) {
      groups.push({
        key: section["@id"],
        section,
        tasks: bySection.get(section["@id"]) ?? [],
      });
    }
    return groups;
  }, [tasks, sections]);

  // List-view section order is a personal preference (localStorage) so the
  // default "In progress" group can be reordered too (it has no DB row to
  // carry a position). Unknown/new groups fall to the end; the board keeps
  // its own default-first order.
  const orderedSectionGroups = useMemo(() => {
    const order = listView.sectionOrder;
    if (order.length === 0) return sectionGroups;
    const byKey = new Map(sectionGroups.map((g) => [g.key, g]));
    const seen = new Set<string>();
    const out: typeof sectionGroups = [];
    for (const key of order) {
      const g = byKey.get(key);
      if (g && !seen.has(key)) {
        out.push(g);
        seen.add(key);
      }
    }
    for (const g of sectionGroups) if (!seen.has(g.key)) out.push(g);
    return out;
  }, [sectionGroups, listView.sectionOrder]);
  const sectionIds = orderedSectionGroups.map((g) => g.key);

  // Move a task within / across sections by drag. `overId` is either another
  // task IRI (drop next to it) or a section group key (drop into that section).
  // We rebuild the global display order, set the moved task's section, then
  // reassign positions sequentially and persist every row that shifted.
  const moveTask = useCallback(
    (activeId: string, overId: string) => {
      if (activeId === overId) return;
      const ordered = orderedSectionGroups.flatMap((g) => g.tasks);
      const fromIdx = ordered.findIndex((t) => t["@id"] === activeId);
      if (fromIdx === -1) return;
      const active = ordered[fromIdx];

      let targetSection: string | null;
      let toIdx: number;
      if (overId.startsWith("/tasks/")) {
        const oi = ordered.findIndex((t) => t["@id"] === overId);
        if (oi === -1) return;
        targetSection = ordered[oi].section ?? null;
        toIdx = oi;
      } else {
        targetSection = overId === DEFAULT_SECTION_KEY ? null : overId;
        let last = -1;
        ordered.forEach((t, i) => {
          if ((t.section ?? null) === targetSection) last = i;
        });
        toIdx = last === -1 ? ordered.length : last + 1;
      }

      const without = ordered.filter((t) => t["@id"] !== activeId);
      const insertAt = toIdx > fromIdx ? toIdx - 1 : toIdx;
      without.splice(insertAt, 0, { ...active, section: targetSection });
      const repositioned = without.map((t, i) => ({ ...t, position: i }));

      // Persist only the rows whose position or section actually changed.
      repositioned.forEach((t) => {
        const before = ordered.find((p) => p["@id"] === t["@id"]);
        if (
          before &&
          (before.position !== t.position ||
            (before.section ?? null) !== (t.section ?? null))
        ) {
          void fetch(`${ENTRYPOINT}${t["@id"]}`, {
            method: "PATCH",
            credentials: "include",
            headers: { "Content-Type": "application/merge-patch+json" },
            body: JSON.stringify({ position: t.position, section: t.section ?? null }),
          });
        }
      });
      setTasks(repositioned);
    },
    [orderedSectionGroups],
  );

  // While a section is being dragged, collapse just that section to its header
  // (Asana-style) so it reads as a compact row dropping above/below the others.
  const [draggingSectionId, setDraggingSectionId] = useState<string | null>(null);
  const onTbodyDragStart = (event: DragStartEvent) => {
    const id = String(event.active.id);
    if (!id.startsWith("/tasks/")) {
      setDraggingSectionId(id);
    }
  };

  // Drag-aware collisions: a section being dragged should only snap to other
  // section rows (so it visibly reorders among sections rather than chasing
  // the task rows between them); tasks use the default closest-center.
  const tbodyCollision: CollisionDetection = (args) => {
    const activeId = String(args.active.id);
    if (!activeId.startsWith("/tasks/")) {
      const sections = args.droppableContainers.filter(
        (c) => !String(c.id).startsWith("/tasks/"),
      );
      return closestCenter({ ...args, droppableContainers: sections });
    }
    return closestCenter(args);
  };

  // Live cross-section move: while dragging a task over another section's
  // rows (or its title), reassign its section in local state so it animates
  // into place mid-drag. onDragEnd then commits the final order + positions.
  const onTbodyDragOver = (event: DragOverEvent) => {
    const { active, over } = event;
    if (!over) return;
    const activeId = String(active.id);
    if (!activeId.startsWith("/tasks/")) return;
    const overId = String(over.id);
    let targetSection: string | null;
    if (overId.startsWith("/tasks/")) {
      const overTask = tasks.find((t) => t["@id"] === overId);
      if (!overTask) return;
      targetSection = overTask.section ?? null;
    } else {
      targetSection = overId === DEFAULT_SECTION_KEY ? null : overId;
    }
    setTasks((prev) => {
      const activeTask = prev.find((t) => t["@id"] === activeId);
      if (!activeTask || (activeTask.section ?? null) === targetSection) {
        return prev;
      }
      return prev.map((t) =>
        t["@id"] === activeId ? { ...t, section: targetSection } : t,
      );
    });
  };

  // One drag-end handler for the tbody: task rows (IRI ids) move tasks;
  // everything else is a section-title row reordering its group.
  const onTbodyDragEnd = (event: DragEndEvent) => {
    setDraggingSectionId(null);
    const { active, over } = event;
    if (!over) return;
    const activeId = String(active.id);
    if (activeId.startsWith("/tasks/")) {
      moveTask(activeId, String(over.id));
    } else {
      // A section may be dropped over another section's title OR over a task
      // inside the target section — resolve the drop target to its section key
      // so reordering lands either way.
      const overId = String(over.id);
      let overSectionKey = overId;
      if (overId.startsWith("/tasks/")) {
        const overTask = tasks.find((t) => t["@id"] === overId);
        overSectionKey = overTask
          ? overTask.section ?? DEFAULT_SECTION_KEY
          : overId;
      }
      const from = sectionIds.indexOf(activeId);
      const to = sectionIds.indexOf(overSectionKey);
      if (from !== -1 && to !== -1 && from !== to) {
        listView.setSectionOrder(arrayMove(sectionIds, from, to));
      }
    }
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading...</p>
      </div>
    );
  }

  if (notFound) {
    return (
      <div className="min-h-screen bg-background px-4 py-12">
        <Card className="max-w-2xl mx-auto">
          <CardContent className="pt-6">
            <h1 className="text-xl font-bold mb-2">Board not found</h1>
            <p className="text-muted-foreground mb-4">
              It may have been deleted, or you may not be a member.
            </p>
            <Link href="/boards" className="text-primary font-medium">
              Back to boards
            </Link>
          </CardContent>
        </Card>
      </div>
    );
  }

  const space = board
    ? spaces.find((s) => s["@id"] === boardSpaceIri(board))
    : undefined;
  return (
    <>
      <Head>
        <title>{board ? `${board.title} - Madori` : "Board - Madori"}</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-8">
        <div className="w-full">
          {isLoading || !board ? (
            <p className="text-muted-foreground">Loading board...</p>
          ) : (
            <>
              <Tabs value={activeTab} onValueChange={setActiveTab}>
                {/* Board title + tabs stick together on scroll. */}
                <div
                  ref={stickyHeaderRef}
                  className="sticky top-14 z-30 bg-background pt-2"
                >
                  <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <h1 className="text-2xl font-bold">{board.title}</h1>
                      {space?.isPersonal && (
                        <Lock className="h-3.5 w-3.5 text-muted-foreground" aria-hidden />
                      )}
                    </div>
                    {activeTab === "list" && (
                      <div className="flex items-center">
                        <Button
                          size="sm"
                          className="rounded-r-none"
                          onClick={focusDefaultAddRow}
                          data-testid="board-new-task"
                        >
                          <Plus className="mr-1 h-3.5 w-3.5" /> New task
                        </Button>
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button
                              size="sm"
                              className="rounded-l-none border-l border-primary-foreground/25 px-1.5"
                              aria-label="More add options"
                              data-testid="board-add-menu"
                            >
                              <ChevronDown className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={focusDefaultAddRow}>
                              <Plus className="mr-2 h-4 w-4" /> New task
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              onClick={() => void createSection()}
                              data-testid="board-add-section"
                            >
                              <Rows3 className="mr-2 h-4 w-4" /> Add section
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    )}
                  </div>

                  <TabsList variant="line">
                    <TabsTrigger value="list">List</TabsTrigger>
                    <TabsTrigger value="board">Board</TabsTrigger>
                    <TabsTrigger value="calendar">Calendar</TabsTrigger>
                    <TabsTrigger value="timeline" data-testid="board-timeline-tab">
                      Timeline
                    </TabsTrigger>
                    <TabsTrigger value="fields" data-testid="board-fields-tab">
                      Custom fields
                    </TabsTrigger>
                    <TabsTrigger value="activity">Activity</TabsTrigger>
                    <TabsTrigger value="settings" data-testid="board-settings-tab">
                      Settings
                    </TabsTrigger>
                  </TabsList>
                </div>

                {error && (
                  <Alert variant="destructive" className="mt-4">
                    <AlertDescription>{error}</AlertDescription>
                  </Alert>
                )}

                <TabsContent value="settings" className="mt-4">
                  <div className="mx-auto max-w-4xl space-y-6">
                    {/* Board name + description. */}
                    <Card>
                      <CardContent className="space-y-4 pt-6">
                        <div>
                          <h3 className="text-sm font-medium">Board details</h3>
                          <p className="text-xs text-muted-foreground">
                            The board&apos;s name and description.
                          </p>
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="board-name">Name</Label>
                          <Input
                            id="board-name"
                            type="text"
                            value={nameDraft}
                            onChange={(e) => setNameDraft(e.target.value)}
                            maxLength={255}
                            data-testid="board-name-input"
                          />
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="board-description">
                            Description{" "}
                            <span className="font-normal text-muted-foreground">
                              (optional)
                            </span>
                          </Label>
                          <MarkdownEditor
                            id="board-description"
                            ariaLabel="Board description"
                            value={descDraft}
                            onChange={setDescDraft}
                          />
                        </div>
                        <div className="flex items-center gap-3">
                          <Button
                            type="button"
                            size="sm"
                            onClick={handleSaveDetails}
                            disabled={isSavingDetails || !nameDraft.trim() || !detailsDirty}
                            data-testid="board-details-save"
                          >
                            {isSavingDetails ? "Saving…" : "Save changes"}
                          </Button>
                          {detailsMessage && (
                            <span
                              role="alert"
                              className={cn(
                                "text-xs",
                                detailsMessage.kind === "success"
                                  ? "text-muted-foreground"
                                  : "text-destructive",
                              )}
                            >
                              {detailsMessage.text}
                            </span>
                          )}
                        </div>
                      </CardContent>
                    </Card>

                    {/* Timeline (#timeline): a single on/off toggle. Enabling
                        attaches the shared global "Start date" field. */}
                    <Card>
                      <CardContent className="pt-6">
                        <div className="flex items-start justify-between gap-4">
                          <div>
                            <h3 className="text-sm font-medium">Timeline</h3>
                            <p className="max-w-md text-xs text-muted-foreground">
                              Show a Gantt view of this board. Each task&apos;s bar
                              runs from the shared <strong>Start date</strong> field
                              to its due date; tasks with only a due date show as
                              milestones. Enabling adds the Start date field to this
                              board — you can&apos;t remove it while Timeline is on.
                            </p>
                          </div>
                          <Switch
                            data-testid="timeline-enabled-switch"
                            checked={board.timelineEnabled ?? false}
                            onCheckedChange={(checked) =>
                              void handleSetTimelineEnabled(checked === true)
                            }
                            aria-label="Enable Timeline"
                          />
                        </div>
                      </CardContent>
                    </Card>

                    <div className="space-y-6">
                          <Card>
                            <CardContent className="space-y-3 pt-6">
                              <div>
                                <h3 className="text-sm font-medium">Move or copy</h3>
                                <p className="text-xs text-muted-foreground">
                                  Relocate this board to another space, or duplicate it.
                                </p>
                              </div>
                              <div
                                className="flex flex-wrap items-center gap-2"
                                data-testid="board-move-form"
                              >
                                <Label
                                  htmlFor="board-move-target"
                                  className="text-xs text-muted-foreground"
                                >
                                  Move to
                                </Label>
                                <select
                                  id="board-move-target"
                                  value={moveTargetIri}
                                  onChange={(e) => setMoveTargetIri(e.target.value)}
                                  className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                                  data-testid="board-move-select"
                                >
                                  <option value="">Pick a space…</option>
                                  {spaces
                                    .filter((s) => s["@id"] !== boardSpaceIri(board))
                                    .map((s) => (
                                      <option key={s["@id"]} value={s["@id"]}>
                                        {s.name}
                                        {s.isPersonal ? " (Private)" : ""}
                                      </option>
                                    ))}
                                </select>
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="outline"
                                  onClick={handleMove}
                                  disabled={!moveTargetIri || isMoving || isCopying}
                                  data-testid="board-move-submit"
                                >
                                  {isMoving ? "Moving…" : "Move"}
                                </Button>
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="outline"
                                  onClick={handleCopy}
                                  disabled={isMoving || isCopying}
                                  data-testid="board-copy-submit"
                                >
                                  {isCopying ? "Copying…" : "Copy"}
                                </Button>
                                <label className="flex items-center gap-1.5 text-xs text-muted-foreground select-none">
                                  <input
                                    type="checkbox"
                                    checked={copyIncludeTasks}
                                    onChange={(e) => setCopyIncludeTasks(e.target.checked)}
                                    className="h-3.5 w-3.5"
                                    data-testid="board-copy-include-tasks"
                                  />
                                  include tasks
                                </label>
                                {moveMessage && (
                                  <span
                                    role="alert"
                                    className={cn(
                                      "text-xs",
                                      moveMessage.kind === "success"
                                        ? "text-muted-foreground"
                                        : "text-destructive",
                                    )}
                                  >
                                    {moveMessage.text}
                                  </span>
                                )}
                              </div>
                            </CardContent>
                          </Card>

                          <Card className="border-destructive/40">
                            <CardContent className="space-y-3 pt-6">
                              <div>
                                <h3 className="text-sm font-medium text-destructive">
                                  Delete this board
                                </h3>
                                <p className="text-xs text-muted-foreground">
                                  Permanently delete this board and all of its tasks.
                                  This can&apos;t be undone.
                                </p>
                              </div>
                              <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                onClick={() => setConfirmDeleteBoardOpen(true)}
                                data-testid="board-delete"
                              >
                                <Trash2 className="mr-1 h-3.5 w-3.5" /> Delete board
                              </Button>
                            </CardContent>
                          </Card>
                    </div>
                  </div>

                  <ConfirmDialog
                    open={confirmDeleteBoardOpen}
                    onOpenChange={setConfirmDeleteBoardOpen}
                    title="Delete this board?"
                    description={`"${board.title}" and all of its tasks will be permanently deleted. This can't be undone.`}
                    confirmLabel="Delete board"
                    onConfirm={handleDeleteBoard}
                  />
                </TabsContent>

                <TabsContent value="fields" className="mt-4">
                  <div className="max-w-5xl">
                    <BoardCustomFieldPicker
                      spaceIri={boardSpaceIri(board)}
                      boardIri={board["@id"]}
                      attachedIris={definitions
                        .filter((d) => !isGlobalDefinition(d))
                        .map((d) => d["@id"])}
                      attachedGlobalIris={definitions
                        .filter((d) => isGlobalDefinition(d))
                        .map((d) => d["@id"])}
                      boardVisibility={Object.fromEntries(
                        definitions.map((d) => [d["@id"], d.visibility ?? "both"]),
                      )}
                      isSpaceAdmin
                      onEdit={(def) => setEditFieldDef(def)}
                      onChanged={() => void reloadDefinitions()}
                    />
                  </div>
                </TabsContent>

                <TabsContent value="list" className="mt-4">
                  {/* The column header lives in its own table outside the section
                      tables, so one header spans them all and sticks to the page
                      below the navbar. Each section is then its own table wrapped
                      in a sortable element, so a dragged section animates as a
                      single block. Every table shares the same fixed colgroup, so
                      columns stay aligned across the header + all sections. */}
                  <div ref={listContainerRef} data-testid="board-task-list">
                    {/* Column header — its own horizontal DnD context for column
                        reorder, wrapping the table (not nested inside it) so the
                        context's injected a11y nodes stay valid DOM. */}
                    <DndContext
                      sensors={listSensors}
                      collisionDetection={closestCenter}
                      onDragEnd={onColumnDragEnd}
                    >
                      <div
                        className="sticky z-20 overflow-hidden rounded-md border-b bg-muted"
                        style={{ top: columnHeaderTop }}
                      >
                        <table className="w-full table-fixed text-sm">
                          <TaskTableColumns columns={columns} widths={colWidths} />
                          <thead>
                            <tr className="text-xs uppercase tracking-wide text-muted-foreground">
                              <th className="px-3 py-2" />
                              <SortableContext
                                items={columnKeys}
                                strategy={horizontalListSortingStrategy}
                              >
                                {columns.map((column) => (
                                  <SortableHeaderCell
                                    key={column.key}
                                    column={column}
                                    sort={listView.sort}
                                    filter={listView.filters[column.key]}
                                    onSetSort={listView.setSort}
                                    onSetFilter={listView.setFilter}
                                    assignableUsers={boardAssignableUsers}
                                    allTags={allTags}
                                    onEdit={
                                      column.definition
                                        ? () => setEditFieldDef(column.definition ?? null)
                                        : undefined
                                    }
                                  />
                                ))}
                              </SortableContext>
                              <th className="px-2 py-2">
                                <div className="flex justify-end">
                                  <AddFieldMenu
                                    hiddenFields={hiddenListFields}
                                    onEnable={enableListField}
                                    onCreate={(kind, subtype) =>
                                      setNewFieldType({ kind, subtype })
                                    }
                                  />
                                </div>
                              </th>
                            </tr>
                          </thead>
                        </table>
                      </div>
                    </DndContext>
                    {/* Section tables: each section is one sortable block. */}
                    <DndContext
                      sensors={listSensors}
                      collisionDetection={tbodyCollision}
                      onDragStart={onTbodyDragStart}
                      onDragOver={onTbodyDragOver}
                      onDragEnd={onTbodyDragEnd}
                      onDragCancel={() => setDraggingSectionId(null)}
                    >
                      <SortableContext
                        items={sectionIds}
                        strategy={verticalListSortingStrategy}
                      >
                        {orderedSectionGroups.map((group) => (
                          <SectionRows
                            key={group.key}
                            sortId={group.key}
                            section={group.section}
                            tasks={group.tasks}
                            columns={columns}
                            colWidths={colWidths}
                            fullColSpan={fullColSpan}
                            boardId={board.id}
                            boardIri={board["@id"]}
                            spaceIri={boardSpaceIri(board)}
                            allTags={allTags}
                            assignableUsers={boardAssignableUsers}
                            sort={listView.sort}
                            filters={listView.filters}
                            footerKey={footerKey}
                            dragging={draggingSectionId === group.key}
                            addTriggerRef={
                              group.section ? undefined : defaultAddTriggerRef
                            }
                            onCreate={createTaskInSection}
                            onToggle={toggleComplete}
                            onOpen={openTaskDetail}
                            onDuplicateTask={duplicateTask}
                            onDeleteTask={deleteTask}
                            patchTask={patchTask}
                            onCustomFieldChange={handleCustomFieldChange}
                            onCreateTag={createTag}
                            onRename={renameSection}
                            onDelete={deleteSection}
                          />
                        ))}
                      </SortableContext>
                    </DndContext>
                    {/* Grand total across the whole board, in its own table. */}
                    {sectionGroups.length > 1 && (
                      <div className="mt-8 overflow-hidden rounded-md">
                        <table className="w-full table-fixed text-sm">
                          <TaskTableColumns columns={columns} widths={colWidths} />
                          <tbody>
                            <CustomFieldFooterRow
                              boardId={board.id}
                              refreshKey={footerKey}
                              columns={columns}
                              asRow
                              prominent
                            />
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                </TabsContent>

                <TabsContent value="board" className="mt-4">
                  <div className="mb-3 flex flex-wrap items-center gap-2">
                    <FilterMultiSelect
                      label="Assignees"
                      options={boardAssigneeOptions}
                      selected={boardAssignees}
                      onChange={setBoardAssignees}
                      testId="board-assignee-filter"
                    />
                    <FilterMultiSelect
                      label="Tags"
                      options={boardTagOptions}
                      selected={boardTags}
                      onChange={setBoardTags}
                      testId="board-tag-filter"
                    />
                  </div>
                  <TaskBoard
                    definitions={boardDefinitions}
                    assignableUsers={boardAssignableUsers}
                    columns={orderedSectionGroups.map((group) => ({
                      key: group.key,
                      sectionIri: group.section ? group.section["@id"] : null,
                      title: group.section
                        ? group.section.title
                        : DEFAULT_SECTION_LABEL,
                      tasks: group.tasks.filter(
                        (t) =>
                          (boardAssignees.size === 0 ||
                            t.assignees.some((a) => boardAssignees.has(a["@id"]))) &&
                          (boardTags.size === 0 ||
                            t.tags.some((tag) => boardTags.has(tag["@id"]))),
                      ),
                    }))}
                    onOpen={(taskIri) => {
                      const task = tasks.find((t) => t["@id"] === taskIri);
                      if (task) openTaskDetail(task);
                    }}
                    onMove={moveTaskToSection}
                    onReorderSections={(activeKey, overKey) => {
                      const from = sectionIds.indexOf(activeKey);
                      const to = sectionIds.indexOf(overKey);
                      if (from !== -1 && to !== -1 && from !== to) {
                        listView.setSectionOrder(arrayMove(sectionIds, from, to));
                      }
                    }}
                    onAssign={(taskIri, iris) => {
                      const task = tasks.find((t) => t["@id"] === taskIri);
                      if (task) void patchTask(task, { assignees: iris });
                    }}
                    onAddTask={() => {
                      // Every section has a persistent add row now; just jump
                      // to the list and focus the default one.
                      setActiveTab("list");
                      focusDefaultAddRow();
                    }}
                    onAddSection={(title) => void createSection(title)}
                    onDeleteSection={(sectionIri) => {
                      const section = sections.find(
                        (s) => s["@id"] === sectionIri,
                      );
                      if (section) void deleteSection(section);
                    }}
                  />
                </TabsContent>

                <TabsContent value="calendar" className="mt-4">
                  {/* Same calendar as the top-level /calendar, filtered to this
                      board (issue #442). */}
                  <CalendarView
                    spaceIri={boardSpaceIri(board)}
                    boardIri={board["@id"]}
                    onOpen={openTaskById}
                    onTasksChanged={() => void load()}
                    refreshSignal={calendarRefresh}
                    assignableUsers={boardAssignableUsers}
                  />
                </TabsContent>

                <TabsContent value="timeline" className="mt-4">
                  <BoardTimeline
                    boardId={board.id}
                    tasks={tasks}
                    sections={sections}
                    startFieldIri={timelineStartFieldIri}
                    onOpenTask={(t) => openTaskDetail(t)}
                    onMoveDue={(t, iso) => void patchTask(t, { dueDate: iso })}
                    onMoveStart={(t, dateStr) =>
                      timelineStartFieldIri &&
                      handleCustomFieldChange(t, timelineStartFieldIri, dateStr)
                    }
                  />
                </TabsContent>

                <TabsContent value="activity" className="mt-4">
                  <ActivityPanel endpoint={`/boards/${board.id}/activity`} />
                </TabsContent>
              </Tabs>
            </>
          )}
        </div>
      </div>

      <TaskDetailDrawer
        taskId={activeTaskId}
        open={Boolean(activeTaskId)}
        onOpenChange={closeTaskDetail}
        currentUserIri={currentUserIri}
        assignableUsers={boardAssignableUsers}
        allTags={allTags}
        onTaskChanged={(updated) => {
          setTasks((prev) =>
            prev.map((t) =>
              t["@id"] === updated["@id"]
                ? {
                    ...t,
                    title: updated.title,
                    completedOn: updated.completedOn,
                    dueDate: updated.dueDate,
                    tags: updated.tags,
                    assignees: updated.assignees,
                    recurrenceRule: updated.recurrenceRule,
                    reminders: updated.reminders,
                    customFieldValues: updated.customFieldValues,
                  }
                : t,
            ),
          );
          setCalendarRefresh((k) => k + 1);
        }}
        onTaskDeleted={(iri) => {
          setTasks((prev) => prev.filter((t) => t["@id"] !== iri));
          setCalendarRefresh((k) => k + 1);
        }}
      />

      {/* Field editor sheet: create (from the add-column "+") or edit an
          existing field (from a column header's options menu). */}
      {board && (
        <CustomFieldSheet
          open={newFieldType !== null || editFieldDef !== null}
          onOpenChange={(o) => {
            if (!o) {
              setNewFieldType(null);
              setEditFieldDef(null);
            }
          }}
          spaceIri={boardSpaceIri(board)}
          boardIri={board["@id"]}
          initial={editFieldDef ?? undefined}
          initialKind={newFieldType?.kind}
          initialSubtype={newFieldType?.subtype}
          initialPosition={definitions.length}
          onSaved={(def) => {
            const wasCreate = newFieldType !== null;
            setNewFieldType(null);
            setEditFieldDef(null);
            // A field created from a board auto-attaches to it.
            if (wasCreate && def?.["@id"]) {
              void attachFieldToBoard(def["@id"]).then(
                () => void reloadDefinitions(),
              );
            } else {
              void reloadDefinitions();
            }
          }}
          onDeleted={() => {
            setEditFieldDef(null);
            void reloadDefinitions();
          }}
        />
      )}
    </>
  );
};

interface SectionRowsProps {
  section: TaskSection | null;
  /** Stable sortable id for the section's title row (the group key). */
  sortId: string;
  tasks: BoardTask[];
  columns: ListColumn[];
  /** Shared content-aware col widths (keeps every section aligned). */
  colWidths?: Record<string, string>;
  fullColSpan: number;
  boardId: string;
  boardIri: string;
  spaceIri: string;
  allTags: TagOption[];
  assignableUsers: AssigneeOption[];
  sort: SortState | null;
  filters: FilterMap;
  footerKey: number;
  /** True while THIS section is being dragged — collapse it to its header. */
  dragging: boolean;
  addTriggerRef?: RefObject<HTMLButtonElement | null>;
  onCreate: (sectionIri: string | null, draft: NewTaskDraft) => Promise<boolean>;
  onToggle: (task: BoardTask) => void;
  onOpen: (task: BoardTask) => void;
  onDuplicateTask: (task: BoardTask) => void;
  onDeleteTask: (task: BoardTask) => void;
  patchTask: (task: BoardTask, body: Record<string, unknown>) => Promise<void>;
  onCustomFieldChange: (task: BoardTask, defIri: string, value: unknown) => void;
  onCreateTag: (title: string) => Promise<TagOption | null>;
  onRename: (section: TaskSection, title: string) => void | Promise<void>;
  onDelete: (section: TaskSection) => void | Promise<void>;
}

/**
 * One section rendered as a group of rows inside the shared list table: a
 * full-width title row, its filtered/sorted task rows, a persistent
 * add-task row, and the section's aggregate footer row.
 */
const SectionRows = ({
  section,
  tasks,
  columns,
  colWidths,
  fullColSpan,
  boardId,
  boardIri,
  spaceIri,
  allTags,
  assignableUsers,
  sort,
  filters,
  footerKey,
  dragging,
  addTriggerRef,
  onCreate,
  onToggle,
  onOpen,
  onDuplicateTask,
  onDeleteTask,
  patchTask,
  onCustomFieldChange,
  onCreateTag,
  onRename,
  onDelete,
  sortId,
}: SectionRowsProps) => {
  const sectionIri = section ? section["@id"] : null;
  const [collapsed, setCollapsed] = useState(false);
  // Show only the header while this section is being dragged.
  const displayCollapsed = collapsed || dragging;
  // Each section is one sortable block (its own table wrapped in this node), so
  // dragging it moves the header and its task rows together.
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id: sortId });
  const visible = useMemo(
    () => applyView(tasks, columns, sort, filters),
    [tasks, columns, sort, filters],
  );
  const filtersActive = Object.keys(filters).length > 0;
  const footerFilter = sectionIri
    ? `section=${encodeURIComponent(sectionIri)}`
    : "section=none";

  return (
    <div
      ref={setNodeRef}
      // Translate (not Transform) so the dragged section keeps its own size —
      // Transform would add scaleX/scaleY to match neighbour heights, skewing it.
      style={{ transform: CSS.Translate.toString(transform), transition }}
      className={cn(
        "relative mt-8 overflow-hidden rounded-md",
        isDragging && "z-10 opacity-90 shadow-lg",
      )}
    >
      <table className="w-full table-fixed text-sm">
        <TaskTableColumns columns={columns} widths={colWidths} />
        <tbody>
          <SectionTitleRow
            section={section}
            colSpan={fullColSpan}
            collapsed={displayCollapsed}
            count={tasks.length}
            dragHandleProps={{ ...attributes, ...listeners }}
            onToggleCollapsed={() => setCollapsed((c) => !c)}
            onRename={onRename}
            onDelete={onDelete}
          />
      {!displayCollapsed && (
        <SortableContext
          items={visible.map((t) => t["@id"])}
          strategy={verticalListSortingStrategy}
        >
          {visible.map((task) => (
            <BoardTaskRow
              key={task["@id"]}
              task={task}
              columns={columns}
              allTags={allTags}
              assignableUsers={assignableUsers}
              boardIri={boardIri}
              spaceIri={spaceIri}
              onToggle={onToggle}
              onOpen={onOpen}
              onDuplicate={onDuplicateTask}
              onDelete={onDeleteTask}
              patchTask={patchTask}
              onCustomFieldChange={onCustomFieldChange}
              onCreateTag={onCreateTag}
            />
          ))}
        </SortableContext>
      )}
      {!displayCollapsed && tasks.length > 0 && visible.length === 0 && filtersActive && (
        <tr>
          <td
            colSpan={fullColSpan}
            className="py-3 pr-3 pl-[4.5rem] text-sm text-muted-foreground"
          >
            No tasks match the active filters.
          </td>
        </tr>
      )}
      {!displayCollapsed && (
        <AddTaskRow
          columns={columns}
          sectionIri={sectionIri}
          allTags={allTags}
          assignableUsers={assignableUsers}
          onCreate={onCreate}
          onCreateTag={onCreateTag}
          triggerRef={addTriggerRef}
        />
      )}
      {!displayCollapsed && (
        <CustomFieldFooterRow
          boardId={boardId}
          filters={footerFilter}
          refreshKey={footerKey}
          columns={columns}
          asRow
        />
      )}
        </tbody>
      </table>
    </div>
  );
};

/** Full-width section heading row: drag grip + collapse arrow + editable
 *  title + delete. Every section (including the default group) is
 *  drag-reorderable in the list view. */
const SectionTitleRow = ({
  section,
  colSpan,
  collapsed,
  count,
  dragHandleProps,
  onToggleCollapsed,
  onRename,
  onDelete,
}: {
  section: TaskSection | null;
  colSpan: number;
  collapsed: boolean;
  count: number;
  dragHandleProps: ButtonHTMLAttributes<HTMLButtonElement>;
  onToggleCollapsed: () => void;
  onRename: (section: TaskSection, title: string) => void | Promise<void>;
  onDelete: (section: TaskSection) => void | Promise<void>;
}) => {
  return (
    <tr
      className="group border-b"
      data-testid="board-section"
    >
      <td colSpan={colSpan} className="relative py-1.5 pl-[2.375rem] pr-3">
        {/* Drag handle in the left gutter, hover-only — lined up with the
            task rows' handle (same left offset). */}
        <button
          type="button"
          className="absolute left-1.5 top-1/2 -translate-y-1/2 cursor-grab touch-none text-muted-foreground/40 opacity-0 hover:text-foreground group-hover:opacity-100"
          aria-label={`Reorder ${section ? `section "${section.title}"` : DEFAULT_SECTION_LABEL}`}
          data-testid="section-drag"
          {...dragHandleProps}
        >
          <GripVertical className="h-3.5 w-3.5" />
        </button>
        <div className="flex items-center gap-1.5">
          <button
            type="button"
            onClick={onToggleCollapsed}
            aria-expanded={!collapsed}
            aria-label={collapsed ? "Expand section" : "Collapse section"}
            className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
            data-testid="section-collapse"
          >
          {collapsed ? (
            <ChevronRight className="h-4 w-4" />
          ) : (
            <ChevronDown className="h-4 w-4" />
          )}
        </button>
        {section ? (
          <input
            defaultValue={section.title}
            onBlur={(e) => void onRename(section, e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") (e.target as HTMLInputElement).blur();
            }}
            className="w-auto min-w-24 rounded border border-transparent bg-transparent px-1 py-0.5 text-base font-semibold [field-sizing:content] hover:border-input focus:border-input focus:outline-none"
            aria-label="Section title"
            data-testid="section-title-input"
          />
        ) : (
          <span className="px-1 text-base font-semibold" data-testid="section-title">
            {DEFAULT_SECTION_LABEL}
          </span>
        )}
        <span className="text-xs text-muted-foreground tabular-nums">{count}</span>
        {section && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                className="ml-auto rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                aria-label="Section actions"
                data-testid="section-menu"
              >
                <MoreHorizontal className="h-4 w-4" />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                onClick={() => void onDelete(section)}
                className="text-destructive"
                data-testid="section-delete"
              >
                <Trash2 className="mr-2 h-4 w-4" /> Delete section
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        )}
        </div>
      </td>
    </tr>
  );
};

/**
 * Foot-of-section add affordance. Collapsed it's just a "+ Add task" text
 * trigger; clicking it expands into a full draft row (title + due +
 * assignees + tags) that creates the task on Enter and stays open for rapid
 * entry. Escape (or the top "New task" button via `triggerRef`) toggles it.
 */
const AddTaskRow = ({
  columns,
  sectionIri,
  allTags,
  assignableUsers,
  onCreate,
  onCreateTag,
  triggerRef,
}: {
  columns: ListColumn[];
  sectionIri: string | null;
  allTags: TagOption[];
  assignableUsers: AssigneeOption[];
  onCreate: (sectionIri: string | null, draft: NewTaskDraft) => Promise<boolean>;
  onCreateTag: (title: string) => Promise<TagOption | null>;
  triggerRef?: RefObject<HTMLButtonElement | null>;
}) => {
  const [adding, setAdding] = useState(false);
  const [busy, setBusy] = useState(false);
  const [title, setTitle] = useState("");
  const [dueDate, setDueDate] = useState<string | null>(null);
  const [tags, setTags] = useState<TagOption[]>([]);
  const [assignees, setAssignees] = useState<AssigneeOption[]>([]);
  const inputRef = useRef<HTMLInputElement | null>(null);

  const reset = () => {
    setTitle("");
    setDueDate(null);
    setTags([]);
    setAssignees([]);
  };
  const open = () => {
    setAdding(true);
    requestAnimationFrame(() => inputRef.current?.focus());
  };
  const close = () => {
    setAdding(false);
    reset();
  };

  const submit = async () => {
    if (!title.trim() || busy) return;
    setBusy(true);
    const ok = await onCreate(sectionIri, {
      title,
      dueDate,
      assignees: assignees.map((a) => a["@id"]),
      tags: tags.map((t) => t["@id"]),
    });
    setBusy(false);
    if (ok) {
      reset();
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  };

  if (!adding) {
    // Mirror the per-column cell structure of the data + expanded rows (rather
    // than one merged colSpan cell) so the column borders stay aligned and the
    // placeholder doesn't break up the table.
    return (
      <tr className="border-b" data-testid="board-add-task-trigger">
        <td className="px-3 py-2" />
        {columns.map((column) =>
          column.key === "task" ? (
            <td key="task" className="px-2 py-2">
              <button
                ref={triggerRef}
                type="button"
                onClick={open}
                className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                data-testid="board-add-task"
              >
                <Plus className="h-4 w-4" /> Add task
              </button>
            </td>
          ) : (
            <td key={column.key} className="px-2 py-2" />
          ),
        )}
        <td className="px-2 py-2" />
      </tr>
    );
  }

  return (
    <tr className="border-b bg-muted/10" data-testid="board-new-task-row">
      <td className="py-2 pl-[2.375rem] pr-1 align-middle">
        <Plus className="h-4 w-4 text-muted-foreground" aria-hidden />
      </td>
      {columns.map((column) => {
        if (column.key === "task") {
          return (
            <td key="task" className="px-2 py-2">
              <Input
                ref={inputRef}
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") {
                    e.preventDefault();
                    void submit();
                  } else if (e.key === "Escape") {
                    e.preventDefault();
                    close();
                  }
                }}
                placeholder="Task name…"
                maxLength={255}
                disabled={busy}
                elevation={0}
                className="h-8"
                data-testid="board-new-task-title"
              />
            </td>
          );
        }
        if (column.key === "due") {
          return (
            <td key="due" className="px-2 py-2 align-middle" data-testid="new-task-due">
              <DueDateCell
                value={dueDate}
                onChange={setDueDate}
                ariaLabel="Due date for new task"
                testIdPrefix="board-new-task-due"
              />
            </td>
          );
        }
        if (column.key === "assignees") {
          return (
            <td key="assignees" className="px-2 py-2 align-middle" data-testid="new-task-assignees">
              <AssigneesCombobox
                value={assignees}
                options={assignableUsers}
                onChange={(iris) =>
                  setAssignees(
                    iris
                      .map((iri) => assignableUsers.find((u) => u["@id"] === iri))
                      .filter((u): u is AssigneeOption => Boolean(u)),
                  )
                }
                subjectLabel="new task"
                elevation={0}
              />
            </td>
          );
        }
        if (column.key === "tags") {
          return (
            <td key="tags" className="px-2 py-2 align-middle" data-testid="new-task-tags">
              <TagsCombobox
                value={tags}
                options={allTags}
                onChange={(_iris, next) => setTags(next)}
                onCreate={onCreateTag}
                subjectLabel="new task"
                elevation={0}
              />
            </td>
          );
        }
        // Custom fields are set after the task exists.
        return <td key={column.key} className="px-2 py-2 align-middle" />;
      })}
      <td className="px-2 py-2" />
    </tr>
  );
};

/**
 * One draggable list-view column header. The whole label area is the drag
 * handle (no separate grip); a chevron on the right opens the
 * {@link ColumnHeaderMenu} for sort / filter / edit.
 */
const SortableHeaderCell = ({
  column,
  sort,
  filter,
  onSetSort,
  onSetFilter,
  assignableUsers,
  allTags,
  onEdit,
}: {
  column: ListColumn;
  sort: SortState | null;
  filter: FilterValue | undefined;
  onSetSort: (sort: SortState | null) => void;
  onSetFilter: (key: string, value: FilterValue | null) => void;
  assignableUsers: AssigneeOption[];
  allTags: TagOption[];
  onEdit?: () => void;
}) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id: column.key });
  return (
    <th
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        "px-2 py-2 text-left font-medium",
        isDragging && "bg-accent opacity-60",
      )}
      data-testid={`column-th-${column.key}`}
    >
      <ColumnHeaderMenu
        column={column}
        sort={sort}
        filter={filter}
        onSetSort={onSetSort}
        onSetFilter={onSetFilter}
        assignableUsers={assignableUsers}
        allTags={allTags}
        onEdit={onEdit}
        dragHandleProps={{ ...attributes, ...listeners }}
      />
    </th>
  );
};

/** Task name cell: click the text to edit it inline; Enter/blur saves. */
const InlineTaskTitle = ({
  task,
  onSave,
  onOpenDetails,
}: {
  task: BoardTask;
  onSave: (title: string) => void;
  onOpenDetails: () => void;
}) => {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(task.title);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (editing) {
      setDraft(task.title);
      requestAnimationFrame(() => inputRef.current?.select());
    }
  }, [editing, task.title]);

  const commit = () => {
    setEditing(false);
    const next = draft.trim();
    if (next && next !== task.title) onSave(next);
  };

  if (editing) {
    return (
      <Input
        ref={inputRef}
        value={draft}
        elevation={0}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === "Enter") {
            e.preventDefault();
            commit();
          } else if (e.key === "Escape") {
            e.preventDefault();
            setEditing(false);
          }
        }}
        maxLength={255}
        // The surrounding cells draw the editing border (checkbox + task cells
        // via group-has) so it lines up seamlessly; the input itself stays
        // borderless.
        className="border-transparent px-2 font-medium hover:border-transparent focus-visible:border-transparent"
        data-testid="board-task-title-input"
      />
    );
  }

  // The text opens the inline editor; the empty space to its right opens the
  // details panel — so the whole cell is actionable but the two intents are
  // split by where you click.
  return (
    <div className="flex h-full min-h-11 items-center">
      <button
        type="button"
        onClick={() => setEditing(true)}
        className={cn(
          "max-w-full shrink truncate px-2 text-left font-medium",
          task.completedOn && "text-muted-foreground",
        )}
        data-testid="board-task-title"
      >
        {task.title}
      </button>
      <button
        type="button"
        onClick={onOpenDetails}
        aria-label={`Open details for "${task.title}"`}
        className="h-full flex-1 cursor-pointer"
        tabIndex={-1}
      />
    </div>
  );
};

interface BoardTaskRowProps {
  task: BoardTask;
  columns: ListColumn[];
  allTags: TagOption[];
  assignableUsers: AssigneeOption[];
  boardIri: string;
  spaceIri: string;
  onToggle: (task: BoardTask) => void;
  onOpen: (task: BoardTask) => void;
  onDuplicate: (task: BoardTask) => void;
  onDelete: (task: BoardTask) => void;
  patchTask: (task: BoardTask, body: Record<string, unknown>) => Promise<void>;
  onCustomFieldChange: (task: BoardTask, defIri: string, value: unknown) => void;
  onCreateTag: (title: string) => Promise<TagOption | null>;
}

const BoardTaskRow = ({
  task,
  columns,
  allTags,
  assignableUsers,
  boardIri,
  spaceIri,
  onToggle,
  onOpen,
  onDuplicate,
  onDelete,
  patchTask,
  onCustomFieldChange,
  onCreateTag,
}: BoardTaskRowProps) => {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: task["@id"] });

  // Drives both the due-date cell colour and a whole-row tint so the task reads
  // amber when due today ("almost due") and red when overdue, matching the date.
  const dueStatus = dueDateStatus(task.dueDate, !!task.completedOn);

  const cellFor = (column: ListColumn) => {
    switch (column.key) {
      case "task":
        return (
          <InlineTaskTitle
            task={task}
            onSave={(title) => void patchTask(task, { title })}
            onOpenDetails={() => onOpen(task)}
          />
        );
      case "due":
        return (
          <DueDateCell
            value={task.dueDate}
            onChange={(next) => void patchTask(task, { dueDate: next })}
            ariaLabel={`Due date for "${task.title}"`}
            testIdPrefix="board-task-due-date"
            recurrenceValue={task.recurrenceRule}
            onRecurrenceChange={(next) =>
              void patchTask(task, { recurrenceRule: next })
            }
            remindersValue={task.reminders}
            onRemindersChange={(next) =>
              void patchTask(task, { reminders: next })
            }
            status={dueStatus}
          />
        );
      case "assignees":
        return (
          // Stacked, name-less avatars + a picker (matches the board and
          // calendar).
          <div className="flex h-full min-h-11 items-center px-2">
            <AssignMenu
              assignees={task.assignees}
              assignableUsers={assignableUsers}
              onAssign={(iris) => void patchTask(task, { assignees: iris })}
              align="start"
            />
          </div>
        );
      case "tags":
        return (
          <TagsCombobox
            value={task.tags}
            options={allTags}
            onChange={(iris) => void patchTask(task, { tags: iris })}
            onCreate={onCreateTag}
            subjectLabel={task.title}
            elevation={0}
          />
        );
      default: {
        const def = column.definition;
        if (!def) return null;
        return (
          <BoardCustomFieldCell
            task={task}
            definition={def}
            boardIri={boardIri}
            spaceIri={spaceIri}
            users={assignableUsers}
            onCustomFieldChange={onCustomFieldChange}
          />
        );
      }
    }
  };

  return (
    <tr
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        "group border-b last:border-0 hover:bg-accent/40",
        dueStatus === "today" && "text-amber-600 dark:text-amber-400",
        dueStatus === "overdue" && "text-destructive",
        // Completed tasks read as a uniformly muted row (no strikethrough);
        // last so it wins over any residual due tint.
        task.completedOn && "text-muted-foreground",
        isDragging && "relative z-10 bg-background opacity-80",
      )}
      data-testid="board-task-item"
    >
      <td
        className={cn(
          "relative py-2 pl-[2.375rem] pr-1 align-middle",
          // While the title is being edited, extend the editor's top/bottom
          // border left across the checkbox cell so it reads as one field
          // spanning to the row's left edge.
          "group-has-[[data-testid=board-task-title-input]]:border-y group-has-[[data-testid=board-task-title-input]]:border-input",
        )}
      >
        {/* Drag handle in the left gutter, revealed on row hover. */}
        <button
          type="button"
          {...attributes}
          {...listeners}
          aria-label={`Reorder "${task.title}"`}
          className="absolute left-1.5 top-1/2 -translate-y-1/2 cursor-grab touch-none text-muted-foreground/40 opacity-0 hover:text-foreground group-hover:opacity-100"
          data-testid="board-task-drag"
        >
          <GripVertical className="size-3.5" />
        </button>
        <span className="relative inline-flex size-[18px] items-center justify-center align-middle">
          <Checkbox
            checked={!!task.completedOn}
            onCheckedChange={() => onToggle(task)}
            aria-label={`Mark "${task.title}" as ${task.completedOn ? "open" : "done"}`}
            className="size-[18px] cursor-pointer rounded-md border-muted-foreground/40 data-[state=checked]:border-emerald-600 data-[state=checked]:bg-emerald-600 data-[state=checked]:text-white"
          />
          {/* A faint check at rest hints the box is a complete-toggle; the
              real (white) check + emerald fill take over once completed. */}
          {!task.completedOn && (
            <Check
              className="pointer-events-none absolute inset-0 m-auto h-3.5 w-3.5 text-muted-foreground/40"
              strokeWidth={3}
              aria-hidden
            />
          )}
        </span>
      </td>
      {columns.map((column) => (
        <td
          key={column.key}
          className={cn(
            "p-0 align-middle",
            // Every inside column except Task reads as a grid cell: a left
            // separator + a flush editor that fills the whole cell (hover
            // reveals the border). Task and the trailing column stay borderless.
            // All cells are p-0 so editors fill the cell and editing the title
            // doesn't change the row height.
            column.key === "task"
              ? "min-w-[12rem] group-has-[[data-testid=board-task-title-input]]:border-y group-has-[[data-testid=board-task-title-input]]:border-r group-has-[[data-testid=board-task-title-input]]:border-input"
              : "border-l",
          )}
        >
          {cellFor(column)}
        </td>
      ))}
      {/* Trailing blank column: clicking the empty space opens details; the
          ellipsis opens the row action menu. */}
      <td
        className="cursor-pointer px-2 py-2 align-middle"
        onClick={() => onOpen(task)}
        data-testid="board-task-open-detail"
      >
        <div className="flex justify-end">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              onClick={(e) => e.stopPropagation()}
              aria-label={`Actions for "${task.title}"`}
              className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
              data-testid="board-task-actions"
            >
              <MoreHorizontal className="h-4 w-4" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" onClick={(e) => e.stopPropagation()}>
            <DropdownMenuItem onClick={() => onOpen(task)}>
              <PanelRight className="mr-2 h-4 w-4" /> View details
            </DropdownMenuItem>
            <DropdownMenuItem onClick={() => onDuplicate(task)}>
              <Copy className="mr-2 h-4 w-4" /> Duplicate task
            </DropdownMenuItem>
            <DropdownMenuItem
              onClick={() => onDelete(task)}
              className="text-destructive"
            >
              <Trash2 className="mr-2 h-4 w-4" /> Delete task
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
        </div>
      </td>
    </tr>
  );
};

const BoardCustomFieldCell = ({
  task,
  definition,
  boardIri,
  spaceIri,
  users,
  onCustomFieldChange,
}: {
  task: BoardTask;
  definition: CustomFieldDefinition;
  boardIri: string;
  spaceIri: string;
  users: AssigneeOption[];
  onCustomFieldChange: (
    task: BoardTask,
    defIri: string,
    value: unknown,
  ) => void;
}) => {
  const serverValue =
    task.customFieldValues.find(
      (v) => valuePairDefinitionIri(v) === definition["@id"],
    )?.value ?? null;
  const serverKey = JSON.stringify(serverValue);
  const [value, setValue] = useState<unknown>(() => JSON.parse(serverKey));
  const dirty = useRef(false);

  // Keep the latest commit target in a ref so the debounce effect can depend
  // only on `value` (task/definition change identity on every render).
  const commitRef = useRef<(v: unknown) => void>(() => {});
  useEffect(() => {
    commitRef.current = (v: unknown) =>
      onCustomFieldChange(task, definition["@id"], v);
  });

  // Re-sync from the server copy when it changes and there's no pending edit.
  useEffect(() => {
    if (!dirty.current) setValue(JSON.parse(serverKey));
  }, [serverKey]);

  // Debounced commit so typing into text/number fields doesn't PATCH per key.
  useEffect(() => {
    if (!dirty.current) return;
    const handle = setTimeout(() => {
      dirty.current = false;
      commitRef.current(value);
    }, 600);
    return () => clearTimeout(handle);
  }, [value]);

  return (
    <CustomFieldValueEditor
      definition={definition}
      value={value}
      elevation={0}
      onChange={(next) => {
        dirty.current = true;
        setValue(next);
      }}
      boardIri={boardIri}
      spaceIri={spaceIri}
      users={users}
      compact
    />
  );
};

export default BoardDetail;
