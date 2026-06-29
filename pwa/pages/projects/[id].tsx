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
import { Check, ChevronDown, ChevronRight, Copy, Lock, MoreHorizontal, PanelRight, Plus, Rows3, Table2, Trash2, TriangleAlert } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { randomPaletteColor } from "@/lib/avatarPalette";
import ActivityPanel from "@/components/activity/ActivityPanel";
import TaskBoard from "@/components/projects/TaskBoard";
import TaskCalendar from "@/components/projects/TaskCalendar";
import TaskTableColumns from "@/components/projects/TaskTableColumns";
import ColumnHeaderMenu from "@/components/projects/ColumnHeaderMenu";
import {
  applyView,
  buildColumns,
  orderColumns,
  type FilterMap,
  type FilterValue,
  type ListColumn,
  type SortState,
} from "@/components/projects/listColumns";
import { useProjectListView } from "@/lib/useProjectListView";
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
import ProjectCustomFieldPicker from "@/components/custom-fields/ProjectCustomFieldPicker";
import CustomFieldSheet from "@/components/custom-fields/CustomFieldSheet";
import AddFieldMenu from "@/components/projects/AddFieldMenu";
import { CustomFieldValueEditor } from "@/components/tasks/value-editors";
import DueDateCell from "@/components/tasks/DueDateCell";
import TagsCombobox, { type TagOption } from "@/components/tasks/TagsCombobox";
import AssigneesCombobox, {
  type AssigneeOption,
} from "@/components/tasks/AssigneesCombobox";
import TaskDetailDrawer from "@/components/tasks/TaskDetailDrawer";
import type {
  CustomFieldDefinition,
  CustomFieldKind,
  CustomFieldSubtype,
} from "@/components/custom-fields/types";
import {
  dueDateStatus,
  type Reminder,
  type RecurrenceRule,
} from "@/components/tasks/taskHelpers";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
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

interface Project {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  owner: Member;
  members: Member[];
  space: string | SpaceRef;
}

interface CustomFieldValuePair {
  definition: string;
  value: unknown;
}

interface ProjectTask {
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

const projectSpaceIri = (project: Project): string =>
  typeof project.space === "string" ? project.space : project.space["@id"];

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

const ProjectDetail = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { spaces } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const projectId = typeof id === "string" ? id : null;
  const currentUserIri = user ? `/users/${user.id}` : null;

  const [project, setProject] = useState<Project | null>(null);
  const [tasks, setTasks] = useState<ProjectTask[]>([]);
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
  // Settings sub-section (left menu like the user settings page).
  const [settingsSection, setSettingsSection] = useState<
    "fields" | "danger"
  >("fields");
  const [confirmDeleteProjectOpen, setConfirmDeleteProjectOpen] =
    useState(false);

  // Each section shows a collapsed "+ Add task" trigger that expands into a
  // full draft row (managed locally by AddTaskRow). The top "New task" button
  // clicks the default group's trigger to open + focus it.
  const defaultAddTriggerRef = useRef<HTMLButtonElement | null>(null);
  const focusDefaultAddRow = () =>
    requestAnimationFrame(() => defaultAddTriggerRef.current?.click());

  // The project title + tabs sticky bar has a variable height, so we measure it
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
    (task: ProjectTask) => {
      if (!projectId) return;
      // Interpolate `id` explicitly — relying on router.query alone throws in
      // Next 16 if the dynamic param is momentarily absent during a shallow
      // route transition.
      void router.push(
        {
          pathname: "/projects/[id]",
          query: { ...router.query, id: projectId, task: task.id },
        },
        undefined,
        { shallow: true },
      );
    },
    [router, projectId],
  );
  const closeTaskDetail = useCallback(
    (open: boolean) => {
      if (open || !projectId) return;
      const query = { ...router.query };
      delete query.task;
      query.id = projectId;
      void router.push({ pathname: "/projects/[id]", query }, undefined, {
        shallow: true,
      });
    },
    [router, projectId],
  );

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    if (!projectId) return;
    setError(null);
    setIsLoading(true);
    try {
      const init = {
        credentials: "include" as const,
        headers: { Accept: "application/ld+json" },
      };
      const projectRes = await fetch(
        `${ENTRYPOINT}/projects/${encodeURIComponent(projectId)}`,
        init,
      );
      if (projectRes.status === 404 || projectRes.status === 403) {
        setNotFound(true);
        return;
      }
      if (!projectRes.ok) throw new Error("Failed to load project.");
      const projectData: Project = await projectRes.json();
      setProject(projectData);

      const projectIri = projectData["@id"];
      const [tasksRes, defsRes, sectionsRes, usersRes, tagsRes] = await Promise.all([
        fetch(`${ENTRYPOINT}/tasks?project=${encodeURIComponent(projectIri)}`, init),
        fetch(
          `${ENTRYPOINT}/custom_field_definitions?projects=${encodeURIComponent(projectIri)}`,
          init,
        ),
        fetch(
          `${ENTRYPOINT}/task_sections?project=${encodeURIComponent(projectIri)}`,
          init,
        ),
        fetch(`${ENTRYPOINT}/me/assignable-users`, init),
        fetch(
          `${ENTRYPOINT}/tags?space=${encodeURIComponent(projectSpaceIri(projectData))}`,
          init,
        ),
      ]);
      if (!tasksRes.ok) throw new Error("Failed to load tasks.");
      setTasks(membersOf<ProjectTask>(await tasksRes.json()));
      if (defsRes.ok) {
        setDefinitions(
          membersOf<CustomFieldDefinition>(await defsRes.json()).sort(
            (a, b) => a.position - b.position,
          ),
        );
      }
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
      setError(err instanceof Error ? err.message : "Failed to load project.");
    } finally {
      setIsLoading(false);
    }
  }, [projectId]);

  useEffect(() => {
    if (isAuthenticated && projectId) void load();
  }, [isAuthenticated, projectId, load]);

  // Any change to the task set (inline edit, toggle, create, drawer) re-fetches
  // the aggregate footer so its sums/averages stay live without a reload.
  useEffect(() => {
    setFooterKey((k) => k + 1);
  }, [tasks]);

  // Re-sync the task-column definitions after the Settings-tab manager mutates
  // them, so a created/edited/reordered/deleted field reflects without reload.
  const reloadDefinitions = useCallback(async () => {
    if (!project) return;
    try {
      const res = await fetch(
        `${ENTRYPOINT}/custom_field_definitions?projects=${encodeURIComponent(project["@id"])}`,
        { credentials: "include" },
      );
      if (res.ok) {
        setDefinitions(
          membersOf<CustomFieldDefinition>(await res.json()).sort(
            (a, b) => a.position - b.position,
          ),
        );
      }
    } catch {
      /* keep the current columns on a transient failure */
    }
  }, [project]);

  // Attach a space-owned field to this project (per-project selection M2M).
  const attachFieldToProject = useCallback(
    async (defIri: string) => {
      if (!project) return;
      const current = definitions.map((d) => d["@id"]);
      if (current.includes(defIri)) return;
      try {
        await fetch(`${ENTRYPOINT}${project["@id"]}`, {
          method: "PATCH",
          credentials: "include",
          headers: { "Content-Type": "application/merge-patch+json" },
          body: JSON.stringify({ customFieldDefinitions: [...current, defIri] }),
        });
      } catch {
        /* transient — the field just won't show until retried */
      }
    },
    [project, definitions],
  );

  // Fields that exist on the project but are hidden from the list view
  // (visibility "board") — offered in the add-column menu to re-show.
  const hiddenListFields = useMemo(
    () => definitions.filter((d) => (d.visibility ?? "both") === "board"),
    [definitions],
  );

  // Reveal a hidden field in the list view by widening its visibility.
  const enableListField = useCallback(
    async (def: CustomFieldDefinition) => {
      try {
        const res = await fetch(`${ENTRYPOINT}${def["@id"]}`, {
          method: "PATCH",
          credentials: "include",
          headers: { "Content-Type": "application/merge-patch+json" },
          body: JSON.stringify({ visibility: "both" }),
        });
        if (res.ok) void reloadDefinitions();
      } catch {
        /* transient — leave the field hidden */
      }
    },
    [reloadDefinitions],
  );

  // Create a tag from free text typed into a tags field (Enter / comma).
  const createTag = useCallback(
    async (title: string): Promise<TagOption | null> => {
      if (!project) return null;
      try {
        const res = await fetch(`${ENTRYPOINT}/tags`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/ld+json" },
          // Tags are space-scoped: create it in this project's space. Give
          // it a random palette color so inline-created tags aren't all grey.
          body: JSON.stringify({
            title,
            space: projectSpaceIri(project),
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
    [project],
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
    async (task: ProjectTask, body: Record<string, unknown>) => {
      setError(null);
      try {
        const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
          method: "PATCH",
          credentials: "include",
          headers: { "Content-Type": "application/merge-patch+json" },
          body: JSON.stringify(body),
        });
        if (!res.ok) throw new Error("Failed to update task.");
        const updated: ProjectTask = await res.json();
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
    (task: ProjectTask, defIri: string, value: unknown) => {
      const next = definitions
        .map((def) => {
          const existing = task.customFieldValues.find(
            (v) => v.definition === def["@id"],
          );
          return {
            definition: def["@id"],
            value: def["@id"] === defIri ? value : existing?.value,
          };
        })
        .filter((p) => !isEmptyFieldValue(p.value));
      void patchTask(task, { customFieldValues: next });
    },
    [definitions, patchTask],
  );

  // Definitions surfaced per view. The drawer always shows every field; the
  // list and board honour each field's `visibility` setting (default "both").
  const listDefinitions = useMemo(
    () => definitions.filter((d) => (d.visibility ?? "both") !== "board"),
    [definitions],
  );
  const boardDefinitions = useMemo(
    () => definitions.filter((d) => (d.visibility ?? "both") !== "list"),
    [definitions],
  );

  // The assignee picker must only offer users who can actually be assigned
  // to this project's tasks — its space members — not the caller's whole
  // assignable universe (which spans every space they're in). On a private
  // board that narrows the list to just the user. `assignableUsers` carries
  // the rich avatar/colour shape the picker needs, so we filter it by the
  // project's member IRIs rather than using the bare `project.members`.
  const projectAssignableUsers = useMemo(() => {
    if (!project) return assignableUsers;
    const memberIris = new Set(project.members.map((m) => m["@id"]));
    return assignableUsers.filter((u) => memberIris.has(u["@id"]));
  }, [assignableUsers, project]);

  // Per-user, per-project list-view state (column order + sort + filters),
  // persisted in localStorage. Applied within each section by SectionBlock.
  const listView = useProjectListView(projectId);
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
  const listSensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
  );
  const onColumnDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (over && active.id !== over.id) {
      handleColumnReorder(String(active.id), String(over.id));
    }
  };

  const toggleComplete = async (task: ProjectTask) => {
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
      const updated: ProjectTask = await res.json();
      setTasks((prev) => prev.map((t) => (t["@id"] === task["@id"] ? updated : t)));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update task.");
    }
  };

  // Row action menu: duplicate a task (copy its fields into a new task in the
  // same section) and delete it.
  const duplicateTask = useCallback(
    async (task: ProjectTask) => {
      if (!project) return;
      setError(null);
      try {
        const res = await fetch(`${ENTRYPOINT}/tasks`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/ld+json" },
          body: JSON.stringify({
            title: `${task.title} (copy)`,
            project: project["@id"],
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
        const created: ProjectTask = await res.json();
        setTasks((prev) => [...prev, created]);
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to duplicate task.");
      }
    },
    [project],
  );

  const deleteTask = useCallback(async (task: ProjectTask) => {
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
      if (!project || !title) return false;
      setError(null);
      try {
        const res = await fetch(`${ENTRYPOINT}/tasks`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/ld+json" },
          body: JSON.stringify({
            title,
            project: project["@id"],
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
        const created: ProjectTask = await res.json();
        setTasks((prev) => [...prev, created]);
        return true;
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to create task.");
        return false;
      }
    },
    [project],
  );

  const createSection = async (title = "New section") => {
    if (!project) return;
    const trimmed = title.trim() || "New section";
    try {
      const res = await fetch(`${ENTRYPOINT}/task_sections`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          project: project["@id"],
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

  const handleMove = async () => {
    if (!project || !moveTargetIri) return;
    setIsMoving(true);
    setMoveMessage(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/projects/${encodeURIComponent(project.id)}/move`,
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
          data.detail || data.error || data["hydra:description"] || "Failed to move project.",
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
        text: err instanceof Error ? err.message : "Failed to move project.",
        kind: "error",
      });
    } finally {
      setIsMoving(false);
    }
  };

  const handleCopy = async () => {
    if (!project) return;
    setIsCopying(true);
    setMoveMessage(null);
    try {
      const body: { space?: string; includeTasks?: boolean } = {};
      if (moveTargetIri) body.space = moveTargetIri;
      if (copyIncludeTasks) body.includeTasks = true;
      const res = await fetch(
        `${ENTRYPOINT}/projects/${encodeURIComponent(project.id)}/copy`,
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
          data.detail || data.error || data["hydra:description"] || "Failed to copy project.",
        );
      }
      if (data.id) await router.push(`/projects/${data.id}`);
    } catch (err) {
      setMoveMessage({
        text: err instanceof Error ? err.message : "Failed to copy project.",
        kind: "error",
      });
    } finally {
      setIsCopying(false);
    }
  };

  const handleDeleteProject = async () => {
    if (!project) return;
    const res = await fetch(
      `${ENTRYPOINT}/projects/${encodeURIComponent(project.id)}`,
      { method: "DELETE", credentials: "include" },
    );
    if (!res.ok && res.status !== 204) {
      throw new Error("Failed to delete project.");
    }
    await router.push("/projects");
  };

  // Group tasks by board section. The default "In progress" group (null
  // section) always leads; user sections follow in position order. Empty
  // sections still render so tasks can be added/moved into them.
  const sectionGroups = useMemo(() => {
    const ordered = [...tasks].sort((a, b) => a.position - b.position);
    const bySection = new Map<string, ProjectTask[]>();
    for (const task of ordered) {
      const key = task.section ?? DEFAULT_SECTION_KEY;
      const list = bySection.get(key) ?? [];
      list.push(task);
      bySection.set(key, list);
    }
    const groups: {
      key: string;
      section: TaskSection | null;
      tasks: ProjectTask[];
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
      <div className="min-h-screen bg-muted px-4 py-12">
        <Card className="max-w-2xl mx-auto">
          <CardContent className="pt-6">
            <h1 className="text-xl font-bold mb-2">Project not found</h1>
            <p className="text-muted-foreground mb-4">
              It may have been deleted, or you may not be a member.
            </p>
            <Link href="/projects" className="text-primary font-medium">
              Back to projects
            </Link>
          </CardContent>
        </Card>
      </div>
    );
  }

  const space = project
    ? spaces.find((s) => s["@id"] === projectSpaceIri(project))
    : undefined;
  return (
    <>
      <Head>
        <title>{project ? `${project.title} - Madori` : "Project - Madori"}</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-8">
        <div className="w-full">
          {isLoading || !project ? (
            <p className="text-muted-foreground">Loading project...</p>
          ) : (
            <>
              <Tabs value={activeTab} onValueChange={setActiveTab}>
                {/* Project title + tabs stick together on scroll. */}
                <div
                  ref={stickyHeaderRef}
                  className="sticky top-14 z-30 bg-muted pt-2"
                >
                  <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <h1 className="text-2xl font-bold">{project.title}</h1>
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
                          data-testid="project-new-task"
                        >
                          <Plus className="mr-1 h-3.5 w-3.5" /> New task
                        </Button>
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button
                              size="sm"
                              className="rounded-l-none border-l border-primary-foreground/25 px-1.5"
                              aria-label="More add options"
                              data-testid="project-add-menu"
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
                              data-testid="project-add-section"
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
                    <TabsTrigger value="activity">Activity</TabsTrigger>
                    <TabsTrigger value="settings" data-testid="project-settings-tab">
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
                  <div className="flex flex-col gap-6 sm:flex-row">
                    {/* Left menu, mirroring the user settings nav. */}
                    <nav
                      className="flex shrink-0 gap-1 overflow-x-auto sm:w-44 sm:flex-col"
                      aria-label="Project settings sections"
                      data-testid="project-settings-nav"
                    >
                      {(
                        [
                          { key: "fields", label: "Custom fields", Icon: Table2 },
                          { key: "danger", label: "Danger zone", Icon: TriangleAlert },
                        ] as const
                      ).map(({ key, label, Icon }) => (
                        <button
                          key={key}
                          type="button"
                          onClick={() => setSettingsSection(key)}
                          aria-current={settingsSection === key ? "page" : undefined}
                          className={cn(
                            "flex items-center gap-2 rounded-md px-3 py-2 text-left text-sm whitespace-nowrap transition-colors",
                            settingsSection === key
                              ? "bg-muted font-medium text-foreground"
                              : "text-muted-foreground hover:bg-muted/50 hover:text-foreground",
                            key === "danger" &&
                              settingsSection !== key &&
                              "text-destructive/80 hover:text-destructive",
                          )}
                          data-testid={`project-settings-${key}`}
                        >
                          <Icon className="h-4 w-4 shrink-0" /> {label}
                        </button>
                      ))}
                    </nav>

                    <div className="min-w-0 flex-1 space-y-6">
                      {settingsSection === "fields" && (
                        <ProjectCustomFieldPicker
                          spaceIri={projectSpaceIri(project)}
                          projectIri={project["@id"]}
                          attachedIris={definitions.map((d) => d["@id"])}
                          projectVisibility={Object.fromEntries(
                            definitions.map((d) => [
                              d["@id"],
                              d.visibility ?? "both",
                            ]),
                          )}
                          isSpaceAdmin
                          onCreate={() =>
                            setNewFieldType({ kind: "text", subtype: "text" })
                          }
                          onEdit={(def) => setEditFieldDef(def)}
                          onChanged={() => void reloadDefinitions()}
                        />
                      )}

                      {settingsSection === "danger" && (
                        <div className="space-y-6">
                          <Card>
                            <CardContent className="space-y-3 pt-6">
                              <div>
                                <h3 className="text-sm font-medium">Move or copy</h3>
                                <p className="text-xs text-muted-foreground">
                                  Relocate this project to another space, or duplicate it.
                                </p>
                              </div>
                              <div
                                className="flex flex-wrap items-center gap-2"
                                data-testid="project-move-form"
                              >
                                <Label
                                  htmlFor="project-move-target"
                                  className="text-xs text-muted-foreground"
                                >
                                  Move to
                                </Label>
                                <select
                                  id="project-move-target"
                                  value={moveTargetIri}
                                  onChange={(e) => setMoveTargetIri(e.target.value)}
                                  className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                                  data-testid="project-move-select"
                                >
                                  <option value="">Pick a space…</option>
                                  {spaces
                                    .filter((s) => s["@id"] !== projectSpaceIri(project))
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
                                  data-testid="project-move-submit"
                                >
                                  {isMoving ? "Moving…" : "Move"}
                                </Button>
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="outline"
                                  onClick={handleCopy}
                                  disabled={isMoving || isCopying}
                                  data-testid="project-copy-submit"
                                >
                                  {isCopying ? "Copying…" : "Copy"}
                                </Button>
                                <label className="flex items-center gap-1.5 text-xs text-muted-foreground select-none">
                                  <input
                                    type="checkbox"
                                    checked={copyIncludeTasks}
                                    onChange={(e) => setCopyIncludeTasks(e.target.checked)}
                                    className="h-3.5 w-3.5"
                                    data-testid="project-copy-include-tasks"
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
                                  Delete this project
                                </h3>
                                <p className="text-xs text-muted-foreground">
                                  Permanently delete this project and all of its tasks.
                                  This can&apos;t be undone.
                                </p>
                              </div>
                              <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                onClick={() => setConfirmDeleteProjectOpen(true)}
                                data-testid="project-delete"
                              >
                                <Trash2 className="mr-1 h-3.5 w-3.5" /> Delete project
                              </Button>
                            </CardContent>
                          </Card>
                        </div>
                      )}
                    </div>
                  </div>

                  <ConfirmDialog
                    open={confirmDeleteProjectOpen}
                    onOpenChange={setConfirmDeleteProjectOpen}
                    title="Delete this project?"
                    description={`"${project.title}" and all of its tasks will be permanently deleted. This can't be undone.`}
                    confirmLabel="Delete project"
                    onConfirm={handleDeleteProject}
                  />
                </TabsContent>

                <TabsContent value="list" className="mt-4">
                  {/* The column header lives in its own table outside the section
                      tables, so one header spans them all and sticks to the page
                      below the navbar. Each section is then its own table wrapped
                      in a sortable element, so a dragged section animates as a
                      single block. Every table shares the same fixed colgroup, so
                      columns stay aligned across the header + all sections. */}
                  <div data-testid="project-task-list">
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
                          <TaskTableColumns columns={columns} />
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
                                    assignableUsers={projectAssignableUsers}
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
                            fullColSpan={fullColSpan}
                            projectId={project.id}
                            projectIri={project["@id"]}
                            spaceIri={projectSpaceIri(project)}
                            allTags={allTags}
                            assignableUsers={projectAssignableUsers}
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
                          <TaskTableColumns columns={columns} />
                          <tbody>
                            <CustomFieldFooterRow
                              projectId={project.id}
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
                  <TaskBoard
                    definitions={boardDefinitions}
                    assignableUsers={projectAssignableUsers}
                    columns={orderedSectionGroups.map((group) => ({
                      key: group.key,
                      sectionIri: group.section ? group.section["@id"] : null,
                      title: group.section
                        ? group.section.title
                        : DEFAULT_SECTION_LABEL,
                      tasks: group.tasks,
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
                  <TaskCalendar
                    tasks={tasks}
                    onOpen={(taskIri) => {
                      const task = tasks.find((t) => t["@id"] === taskIri);
                      if (task) openTaskDetail(task);
                    }}
                  />
                </TabsContent>

                <TabsContent value="activity" className="mt-4">
                  <ActivityPanel endpoint={`/projects/${project.id}/activity`} />
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
        assignableUsers={projectAssignableUsers}
        allTags={allTags}
        onTaskChanged={(updated) =>
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
          )
        }
        onTaskDeleted={(iri) =>
          setTasks((prev) => prev.filter((t) => t["@id"] !== iri))
        }
      />

      {/* Field editor sheet: create (from the add-column "+") or edit an
          existing field (from a column header's options menu). */}
      {project && (
        <CustomFieldSheet
          open={newFieldType !== null || editFieldDef !== null}
          onOpenChange={(o) => {
            if (!o) {
              setNewFieldType(null);
              setEditFieldDef(null);
            }
          }}
          spaceIri={projectSpaceIri(project)}
          projectIri={project["@id"]}
          initial={editFieldDef ?? undefined}
          initialKind={newFieldType?.kind}
          initialSubtype={newFieldType?.subtype}
          initialPosition={definitions.length}
          onSaved={(def) => {
            const wasCreate = newFieldType !== null;
            setNewFieldType(null);
            setEditFieldDef(null);
            // A field created from a project auto-attaches to it.
            if (wasCreate && def?.["@id"]) {
              void attachFieldToProject(def["@id"]).then(
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
  tasks: ProjectTask[];
  columns: ListColumn[];
  fullColSpan: number;
  projectId: string;
  projectIri: string;
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
  onToggle: (task: ProjectTask) => void;
  onOpen: (task: ProjectTask) => void;
  onDuplicateTask: (task: ProjectTask) => void;
  onDeleteTask: (task: ProjectTask) => void;
  patchTask: (task: ProjectTask, body: Record<string, unknown>) => Promise<void>;
  onCustomFieldChange: (task: ProjectTask, defIri: string, value: unknown) => void;
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
  fullColSpan,
  projectId,
  projectIri,
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
        <TaskTableColumns columns={columns} />
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
            <ProjectTaskRow
              key={task["@id"]}
              task={task}
              columns={columns}
              allTags={allTags}
              assignableUsers={assignableUsers}
              projectIri={projectIri}
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
          projectId={projectId}
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
      data-testid="project-section"
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
    return (
      <tr className="border-b" data-testid="project-add-task-trigger">
        <td className="px-3 py-2" />
        <td colSpan={columns.length + 1} className="px-2 py-2">
          <button
            ref={triggerRef}
            type="button"
            onClick={open}
            className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            data-testid="project-add-task"
          >
            <Plus className="h-4 w-4" /> Add task
          </button>
        </td>
      </tr>
    );
  }

  return (
    <tr className="border-b bg-muted/10" data-testid="project-new-task-row">
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
                data-testid="project-new-task-title"
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
                testIdPrefix="project-new-task-due"
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
  task: ProjectTask;
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
        data-testid="project-task-title-input"
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
          task.completedOn && "text-muted-foreground line-through",
        )}
        data-testid="project-task-title"
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

interface ProjectTaskRowProps {
  task: ProjectTask;
  columns: ListColumn[];
  allTags: TagOption[];
  assignableUsers: AssigneeOption[];
  projectIri: string;
  spaceIri: string;
  onToggle: (task: ProjectTask) => void;
  onOpen: (task: ProjectTask) => void;
  onDuplicate: (task: ProjectTask) => void;
  onDelete: (task: ProjectTask) => void;
  patchTask: (task: ProjectTask, body: Record<string, unknown>) => Promise<void>;
  onCustomFieldChange: (task: ProjectTask, defIri: string, value: unknown) => void;
  onCreateTag: (title: string) => Promise<TagOption | null>;
}

const ProjectTaskRow = ({
  task,
  columns,
  allTags,
  assignableUsers,
  projectIri,
  spaceIri,
  onToggle,
  onOpen,
  onDuplicate,
  onDelete,
  patchTask,
  onCustomFieldChange,
  onCreateTag,
}: ProjectTaskRowProps) => {
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
            testIdPrefix="project-task-due-date"
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
          <AssigneesCombobox
            value={task.assignees}
            options={assignableUsers}
            onChange={(iris) => void patchTask(task, { assignees: iris })}
            subjectLabel={task.title}
            elevation={0}
          />
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
          <ProjectCustomFieldCell
            task={task}
            definition={def}
            projectIri={projectIri}
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
        isDragging && "relative z-10 bg-background opacity-80",
      )}
      data-testid="project-task-item"
    >
      <td
        className={cn(
          "relative py-2 pl-[2.375rem] pr-1 align-middle",
          // While the title is being edited, extend the editor's top/bottom
          // border left across the checkbox cell so it reads as one field
          // spanning to the row's left edge.
          "group-has-[[data-testid=project-task-title-input]]:border-y group-has-[[data-testid=project-task-title-input]]:border-input",
        )}
      >
        {/* Drag handle in the left gutter, revealed on row hover. */}
        <button
          type="button"
          {...attributes}
          {...listeners}
          aria-label={`Reorder "${task.title}"`}
          className="absolute left-1.5 top-1/2 -translate-y-1/2 cursor-grab touch-none text-muted-foreground/40 opacity-0 hover:text-foreground group-hover:opacity-100"
          data-testid="project-task-drag"
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
              ? "min-w-[12rem] group-has-[[data-testid=project-task-title-input]]:border-y group-has-[[data-testid=project-task-title-input]]:border-r group-has-[[data-testid=project-task-title-input]]:border-input"
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
        data-testid="project-task-open-detail"
      >
        <div className="flex justify-end">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              onClick={(e) => e.stopPropagation()}
              aria-label={`Actions for "${task.title}"`}
              className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
              data-testid="project-task-actions"
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

const ProjectCustomFieldCell = ({
  task,
  definition,
  projectIri,
  spaceIri,
  users,
  onCustomFieldChange,
}: {
  task: ProjectTask;
  definition: CustomFieldDefinition;
  projectIri: string;
  spaceIri: string;
  users: AssigneeOption[];
  onCustomFieldChange: (
    task: ProjectTask,
    defIri: string,
    value: unknown,
  ) => void;
}) => {
  const serverValue =
    task.customFieldValues.find((v) => v.definition === definition["@id"])
      ?.value ?? null;
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
      projectIri={projectIri}
      spaceIri={spaceIri}
      users={users}
      compact
    />
  );
};

export default ProjectDetail;
