import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useRef, useState, type RefObject } from "react";
import { ChevronDown, ChevronRight, Lock, MoreHorizontal, PanelRight, Plus, Rows3, Shield, Table2, Trash2, TriangleAlert } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import ActivityPanel from "@/components/activity/ActivityPanel";
import TaskBoard from "@/components/projects/TaskBoard";
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
  type DragEndEvent,
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
import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";
import { CustomFieldValueEditor } from "@/components/tasks/value-editors";
import DueDateCell from "@/components/tasks/DueDateCell";
import TagsCombobox, { type TagOption } from "@/components/tasks/TagsCombobox";
import AssigneesCombobox, {
  type AssigneeOption,
} from "@/components/tasks/AssigneesCombobox";
import TaskDetailDrawer from "@/components/tasks/TaskDetailDrawer";
import type { CustomFieldDefinition } from "@/components/custom-fields/types";
import {
  dueDateStatus,
  type Reminder,
  type RecurrenceRule,
} from "@/components/tasks/taskHelpers";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
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
    "privacy" | "fields" | "danger"
  >("privacy");
  const [confirmDeleteProjectOpen, setConfirmDeleteProjectOpen] =
    useState(false);

  // Every section carries a persistent quick-add row (managed locally by
  // AddTaskRow). The top "New task" button just focuses the default group's
  // add row via this ref.
  const newTaskInputRef = useRef<HTMLInputElement | null>(null);
  const focusDefaultAddRow = () =>
    requestAnimationFrame(() => newTaskInputRef.current?.focus());
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
          `${ENTRYPOINT}/custom_field_definitions?project=${encodeURIComponent(projectIri)}`,
          init,
        ),
        fetch(
          `${ENTRYPOINT}/task_sections?project=${encodeURIComponent(projectIri)}`,
          init,
        ),
        fetch(`${ENTRYPOINT}/me/assignable-users`, init),
        fetch(`${ENTRYPOINT}/tags`, init),
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
        `${ENTRYPOINT}/custom_field_definitions?project=${encodeURIComponent(project["@id"])}`,
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

  // Inline quick-add from a section's persistent add row: create with just the
  // title; everything else is edited in the row afterwards. Returns true on
  // success so the add row can clear + refocus for rapid entry.
  const createTaskInSection = useCallback(
    async (sectionIri: string | null, rawTitle: string): Promise<boolean> => {
      const title = rawTitle.trim();
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

  const createSection = async () => {
    if (!project) return;
    try {
      const res = await fetch(`${ENTRYPOINT}/task_sections`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          project: project["@id"],
          title: "New section",
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
  const onSectionDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const from = sectionIds.indexOf(String(active.id));
    const to = sectionIds.indexOf(String(over.id));
    if (from === -1 || to === -1) return;
    listView.setSectionOrder(arrayMove(sectionIds, from, to));
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
  const spaceId = project
    ? typeof project.space === "string"
      ? project.space.split("/").pop()
      : project.space.id
    : undefined;
  const isSpaceAdmin = Boolean(
    space &&
      user &&
      space.userMemberships.some(
        (m) => m.user.id === user.id && m.role === "admin",
      ),
  );

  return (
    <>
      <Head>
        <title>{project ? `${project.title} - Madori` : "Project - Madori"}</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-8">
        <div className="max-w-7xl mx-auto">
          <Link
            href="/projects"
            className="mb-4 inline-block text-sm text-muted-foreground hover:text-foreground"
          >
            ← All projects
          </Link>

          {isLoading || !project ? (
            <p className="text-muted-foreground">Loading project...</p>
          ) : (
            <>
              {/* Header */}
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

              {error && (
                <Alert variant="destructive" className="mb-4">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <Tabs value={activeTab} onValueChange={setActiveTab}>
                <TabsList variant="line">
                  <TabsTrigger value="list">List</TabsTrigger>
                  <TabsTrigger value="board">Board</TabsTrigger>
                  <TabsTrigger value="activity">Activity</TabsTrigger>
                  <TabsTrigger value="settings" data-testid="project-settings-tab">
                    Settings
                  </TabsTrigger>
                </TabsList>

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
                          { key: "privacy", label: "Privacy", Icon: Shield },
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
                      {settingsSection === "privacy" && (
                        <Card>
                          <CardContent
                            className="space-y-3 pt-6"
                            data-testid="project-space-info"
                          >
                            {project.description && (
                              <p className="text-sm text-muted-foreground">
                                {project.description}
                              </p>
                            )}
                            <div className="flex flex-wrap items-center gap-2">
                              <span className="text-xs text-muted-foreground">Space</span>
                              <Badge variant="secondary" className="gap-1">
                                {space?.isPersonal && (
                                  <Lock className="h-3 w-3" aria-hidden />
                                )}
                                {space?.name ??
                                  (typeof project.space === "string"
                                    ? "Unknown space"
                                    : project.space.name)}
                              </Badge>
                              {spaceId && (
                                <Button
                                  asChild
                                  variant="link"
                                  size="sm"
                                  className="h-auto p-0"
                                >
                                  <Link href={`/spaces/${spaceId}`}>
                                    Manage members in space
                                  </Link>
                                </Button>
                              )}
                            </div>

                            {project.members.length > 0 && (
                              <ul
                                className="flex flex-wrap items-center gap-1"
                                data-testid="member-list"
                              >
                                {project.members.map((member) => (
                                  <li key={member["@id"]} data-testid="member-pill">
                                    <Badge variant="outline">{member.email}</Badge>
                                  </li>
                                ))}
                              </ul>
                            )}
                          </CardContent>
                        </Card>
                      )}

                      {settingsSection === "fields" && (
                        <CustomFieldsManager
                          projectIri={project["@id"]}
                          projectTitle={project.title}
                          spaceName={space?.name}
                          isSpaceAdmin={isSpaceAdmin}
                          onDefinitionsChanged={() => void reloadDefinitions()}
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
                  {/* One continuous table: a single sticky column header at the
                      top, section titles as in-table rows, and a persistent
                      add-task row at the foot of every section. The container
                      scrolls internally so the header can stick. */}
                  <div
                    className="max-h-[calc(100vh-13rem)] overflow-auto rounded-lg border"
                    data-testid="project-task-list"
                  >
                    <table className="w-full table-fixed text-sm">
                      <TaskTableColumns columns={columns} />
                      {/* Column header reorder (horizontal) is its own DnD
                          context, separate from section reorder (vertical) so
                          the two never cross-target. */}
                      <DndContext
                        sensors={listSensors}
                        collisionDetection={closestCenter}
                        onDragEnd={onColumnDragEnd}
                      >
                        <thead className="sticky top-0 z-20 bg-background">
                          <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
                            <th className="w-8 px-3 py-2" />
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
                                />
                              ))}
                            </SortableContext>
                            <th className="w-10 px-2 py-2" />
                          </tr>
                        </thead>
                      </DndContext>
                      <DndContext
                        sensors={listSensors}
                        collisionDetection={closestCenter}
                        onDragEnd={onSectionDragEnd}
                      >
                        <tbody>
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
                                newTaskInputRef={
                                  group.section ? undefined : newTaskInputRef
                                }
                                onCreate={createTaskInSection}
                                onToggle={toggleComplete}
                                onOpen={openTaskDetail}
                                patchTask={patchTask}
                                onCustomFieldChange={handleCustomFieldChange}
                                onRename={renameSection}
                                onDelete={deleteSection}
                              />
                            ))}
                          </SortableContext>
                          {/* Grand total across the whole board. */}
                          {sectionGroups.length > 1 && (
                            <CustomFieldFooterRow
                              projectId={project.id}
                              refreshKey={footerKey}
                              columns={columns}
                              asRow
                              prominent
                            />
                          )}
                        </tbody>
                      </DndContext>
                    </table>
                  </div>
                </TabsContent>

                <TabsContent value="board" className="mt-4">
                  <TaskBoard
                    definitions={boardDefinitions}
                    columns={sectionGroups.map((group) => ({
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
                    onAddTask={() => {
                      // Every section has a persistent add row now; just jump
                      // to the list and focus the default one.
                      setActiveTab("list");
                      focusDefaultAddRow();
                    }}
                    onAddSection={() => void createSection()}
                    onDeleteSection={(sectionIri) => {
                      const section = sections.find(
                        (s) => s["@id"] === sectionIri,
                      );
                      if (section) void deleteSection(section);
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
  newTaskInputRef?: RefObject<HTMLInputElement | null>;
  onCreate: (sectionIri: string | null, title: string) => Promise<boolean>;
  onToggle: (task: ProjectTask) => void;
  onOpen: (task: ProjectTask) => void;
  patchTask: (task: ProjectTask, body: Record<string, unknown>) => Promise<void>;
  onCustomFieldChange: (task: ProjectTask, defIri: string, value: unknown) => void;
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
  newTaskInputRef,
  onCreate,
  onToggle,
  onOpen,
  patchTask,
  onCustomFieldChange,
  onRename,
  onDelete,
  sortId,
}: SectionRowsProps) => {
  const sectionIri = section ? section["@id"] : null;
  const [collapsed, setCollapsed] = useState(false);
  const visible = useMemo(
    () => applyView(tasks, columns, sort, filters),
    [tasks, columns, sort, filters],
  );
  const filtersActive = Object.keys(filters).length > 0;
  const footerFilter = sectionIri
    ? `section=${encodeURIComponent(sectionIri)}`
    : "section=none";

  return (
    <>
      <SectionTitleRow
        section={section}
        sortId={sortId}
        colSpan={fullColSpan}
        collapsed={collapsed}
        count={tasks.length}
        onToggleCollapsed={() => setCollapsed((c) => !c)}
        onRename={onRename}
        onDelete={onDelete}
      />
      {!collapsed &&
        visible.map((task) => (
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
            patchTask={patchTask}
            onCustomFieldChange={onCustomFieldChange}
          />
        ))}
      {!collapsed && tasks.length > 0 && visible.length === 0 && filtersActive && (
        <tr>
          <td
            colSpan={fullColSpan}
            className="px-3 py-3 text-sm text-muted-foreground"
          >
            No tasks match the active filters.
          </td>
        </tr>
      )}
      {!collapsed && (
        <AddTaskRow
          columns={columns}
          sectionIri={sectionIri}
          onCreate={onCreate}
          inputRef={newTaskInputRef}
        />
      )}
      {!collapsed && (
        <CustomFieldFooterRow
          projectId={projectId}
          filters={footerFilter}
          refreshKey={footerKey}
          columns={columns}
          asRow
        />
      )}
    </>
  );
};

/** Full-width section heading row: drag grip + collapse arrow + editable
 *  title + delete. Every section (including the default group) is
 *  drag-reorderable in the list view. */
const SectionTitleRow = ({
  section,
  sortId,
  colSpan,
  collapsed,
  count,
  onToggleCollapsed,
  onRename,
  onDelete,
}: {
  section: TaskSection | null;
  sortId: string;
  colSpan: number;
  collapsed: boolean;
  count: number;
  onToggleCollapsed: () => void;
  onRename: (section: TaskSection, title: string) => void | Promise<void>;
  onDelete: (section: TaskSection) => void | Promise<void>;
}) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id: sortId });
  return (
    <tr
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        "border-b bg-muted/40",
        isDragging && "relative z-10 opacity-70",
      )}
      data-testid="project-section"
    >
      <td colSpan={colSpan} className="px-3 py-1.5">
        <div className="flex items-center gap-1.5">
          <button
            type="button"
            className="cursor-grab touch-none rounded p-0.5 text-muted-foreground/40 hover:text-foreground"
            aria-label={`Reorder ${section ? `section "${section.title}"` : DEFAULT_SECTION_LABEL}`}
            data-testid="section-drag"
            {...attributes}
            {...listeners}
          >
            <GripVertical className="h-3.5 w-3.5" />
          </button>
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
            className="rounded border border-transparent bg-transparent px-1 py-0.5 text-sm font-semibold hover:border-input focus:border-input focus:outline-none"
            aria-label="Section title"
            data-testid="section-title-input"
          />
        ) : (
          <span className="px-1 text-sm font-semibold" data-testid="section-title">
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
 * Persistent quick-add row at the foot of every section. Title-only: Enter
 * creates the task in this section (other fields are edited on the row
 * afterwards). Keeps its own draft so each section adds independently.
 */
const AddTaskRow = ({
  columns,
  sectionIri,
  onCreate,
  inputRef,
}: {
  columns: ListColumn[];
  sectionIri: string | null;
  onCreate: (sectionIri: string | null, title: string) => Promise<boolean>;
  inputRef?: RefObject<HTMLInputElement | null>;
}) => {
  const [title, setTitle] = useState("");
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (!title.trim() || busy) return;
    setBusy(true);
    const ok = await onCreate(sectionIri, title);
    setBusy(false);
    if (ok) setTitle("");
  };

  return (
    <tr className="border-b bg-muted/5" data-testid="project-new-task-row">
      <td className="px-3 py-2 align-middle">
        <Plus className="h-4 w-4 text-muted-foreground" aria-hidden />
      </td>
      {columns.map((column) =>
        column.key === "task" ? (
          <td key="task" className="px-2 py-2">
            <Input
              ref={inputRef}
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  void submit();
                }
              }}
              placeholder="Add a task, then Enter…"
              maxLength={255}
              disabled={busy}
              className="h-8 border-transparent bg-transparent hover:border-input focus-visible:border-input"
              data-testid="project-new-task-title"
            />
          </td>
        ) : (
          <td key={column.key} className="px-2 py-2" />
        ),
      )}
      <td className="px-2 py-2" />
    </tr>
  );
};

/**
 * One draggable list-view column header: a grip to reorder + the
 * {@link ColumnHeaderMenu} for sort/filter. The grip carries the drag
 * listeners so clicking the menu doesn't start a drag.
 */
const SortableHeaderCell = ({
  column,
  sort,
  filter,
  onSetSort,
  onSetFilter,
  assignableUsers,
  allTags,
}: {
  column: ListColumn;
  sort: SortState | null;
  filter: FilterValue | undefined;
  onSetSort: (sort: SortState | null) => void;
  onSetFilter: (key: string, value: FilterValue | null) => void;
  assignableUsers: AssigneeOption[];
  allTags: TagOption[];
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
      <div className="flex items-center gap-1">
        <button
          type="button"
          className="shrink-0 cursor-grab touch-none text-muted-foreground/40 hover:text-foreground"
          aria-label={`Reorder ${column.label} column`}
          {...attributes}
          {...listeners}
        >
          <GripVertical className="size-3.5" />
        </button>
        <ColumnHeaderMenu
          column={column}
          sort={sort}
          filter={filter}
          onSetSort={onSetSort}
          onSetFilter={onSetFilter}
          assignableUsers={assignableUsers}
          allTags={allTags}
        />
      </div>
    </th>
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
  patchTask: (task: ProjectTask, body: Record<string, unknown>) => Promise<void>;
  onCustomFieldChange: (task: ProjectTask, defIri: string, value: unknown) => void;
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
  patchTask,
  onCustomFieldChange,
}: ProjectTaskRowProps) => {
  const cellFor = (column: ListColumn) => {
    switch (column.key) {
      case "task":
        return (
          <button
            type="button"
            onClick={() => onOpen(task)}
            className={cn(
              "w-full cursor-pointer text-left font-medium hover:text-primary",
              task.completedOn && "text-muted-foreground line-through",
            )}
            data-testid="project-task-title"
          >
            {task.title}
          </button>
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
            status={dueDateStatus(task.dueDate, !!task.completedOn)}
          />
        );
      case "assignees":
        return (
          <AssigneesCombobox
            value={task.assignees}
            options={assignableUsers}
            onChange={(iris) => void patchTask(task, { assignees: iris })}
            subjectLabel={task.title}
          />
        );
      case "tags":
        return (
          <TagsCombobox
            value={task.tags}
            options={allTags}
            onChange={(iris) => void patchTask(task, { tags: iris })}
            subjectLabel={task.title}
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
      className="border-b last:border-0 hover:bg-accent/40"
      data-testid="project-task-item"
    >
      <td className="px-3 py-2 align-middle">
        <Checkbox
          checked={!!task.completedOn}
          onCheckedChange={() => onToggle(task)}
          aria-label={`Mark "${task.title}" as ${task.completedOn ? "open" : "done"}`}
          className="size-[18px] cursor-pointer rounded-full border-muted-foreground/40 data-[state=checked]:border-emerald-600 data-[state=checked]:bg-emerald-600 data-[state=checked]:text-white"
        />
      </td>
      {columns.map((column) => (
        <td
          key={column.key}
          className={cn(
            "px-2 py-2 align-middle",
            column.key === "task" && "min-w-[12rem]",
          )}
        >
          {cellFor(column)}
        </td>
      ))}
      <td className="px-2 py-2 align-middle">
        <button
          type="button"
          onClick={() => onOpen(task)}
          aria-label={`Open details for "${task.title}"`}
          className="text-muted-foreground hover:text-foreground"
          data-testid="project-task-open-detail"
        >
          <PanelRight className="h-4 w-4" />
        </button>
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
