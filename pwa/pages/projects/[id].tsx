import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Lock, PanelRight, Plus, Settings2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import ActivityPanel from "@/components/activity/ActivityPanel";
import CustomFieldFooterRow from "@/components/custom-fields/CustomFieldFooterRow";
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
  isoToLocalDate,
  todayLocalMidnight,
  type Reminder,
  type RecurrenceRule,
} from "@/components/tasks/taskHelpers";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
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

// Due-date buckets for the grouped list, in render order.
type Bucket = "overdue" | "week" | "later" | "none" | "done";
const BUCKET_LABELS: Record<Bucket, string> = {
  overdue: "Overdue",
  week: "This week",
  later: "Later",
  none: "No due date",
  done: "Completed",
};
const BUCKET_ORDER: Bucket[] = ["overdue", "week", "later", "none", "done"];

const bucketFor = (task: ProjectTask): Bucket => {
  if (task.completedOn) return "done";
  const status = dueDateStatus(task.dueDate, false);
  if (status === "overdue") return "overdue";
  if (!task.dueDate) return "none";
  const due = isoToLocalDate(task.dueDate);
  if (!due) return "none";
  const weekEnd = todayLocalMidnight();
  weekEnd.setDate(weekEnd.getDate() + 7);
  return due.getTime() <= weekEnd.getTime() ? "week" : "later";
};

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
  const [assignableUsers, setAssignableUsers] = useState<AssigneeOption[]>([]);
  const [allTags, setAllTags] = useState<TagOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Quick add-task (collapsed behind "+ New task").
  const [addOpen, setAddOpen] = useState(false);
  const [newTaskTitle, setNewTaskTitle] = useState("");
  const [isCreatingTask, setIsCreatingTask] = useState(false);
  const newTaskInputRef = useRef<HTMLInputElement | null>(null);

  // Move / copy to space (#182).
  const [settingsOpen, setSettingsOpen] = useState(false);
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
      void router.push(
        { pathname: router.pathname, query: { ...router.query, task: task.id } },
        undefined,
        { shallow: true },
      );
    },
    [router],
  );
  const closeTaskDetail = useCallback(
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
      const [tasksRes, defsRes, usersRes, tagsRes] = await Promise.all([
        fetch(`${ENTRYPOINT}/tasks?project=${encodeURIComponent(projectIri)}`, init),
        fetch(
          `${ENTRYPOINT}/custom_field_definitions?project=${encodeURIComponent(projectIri)}`,
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

  // Inline quick-add: create with just the title; everything else is edited in
  // the row afterwards. Keeps the add row open + refocused for rapid entry.
  const createTask = async () => {
    const title = newTaskTitle.trim();
    if (!project || !title) return;
    setIsCreatingTask(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/tasks`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({ title, project: project["@id"] }),
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
      setNewTaskTitle("");
      requestAnimationFrame(() => newTaskInputRef.current?.focus());
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create task.");
    } finally {
      setIsCreatingTask(false);
    }
  };

  const openAddRow = () => {
    setAddOpen(true);
    requestAnimationFrame(() => newTaskInputRef.current?.focus());
  };
  const closeAddRow = () => {
    setAddOpen(false);
    setNewTaskTitle("");
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

  const grouped = useMemo(() => {
    const ordered = [...tasks].sort((a, b) => a.position - b.position);
    const map = new Map<Bucket, ProjectTask[]>();
    for (const task of ordered) {
      const bucket = bucketFor(task);
      const list = map.get(bucket) ?? [];
      list.push(task);
      map.set(bucket, list);
    }
    return BUCKET_ORDER.map((bucket) => ({
      bucket,
      tasks: map.get(bucket) ?? [],
    })).filter((g) => g.tasks.length > 0);
  }, [tasks]);

  const openCount = tasks.filter((t) => !t.completedOn).length;
  const totalCols = 6 + definitions.length;

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
                  {definitions.length > 0 && (
                    <Badge variant="secondary" className="font-normal">
                      {definitions.length} custom field
                      {definitions.length === 1 ? "" : "s"}
                    </Badge>
                  )}
                  {space?.isPersonal && (
                    <Lock className="h-3.5 w-3.5 text-muted-foreground" aria-hidden />
                  )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setSettingsOpen((v) => !v)}
                    aria-pressed={settingsOpen}
                    data-testid="project-settings-toggle"
                  >
                    <Settings2 className="mr-1 h-3.5 w-3.5" /> Settings
                  </Button>
                  <Button
                    asChild
                    variant="outline"
                    size="sm"
                    data-testid="project-custom-fields-link"
                  >
                    <Link href={`/projects/${project.id}/custom-fields`}>
                      Custom fields
                    </Link>
                  </Button>
                  <Button
                    size="sm"
                    onClick={openAddRow}
                    data-testid="project-new-task"
                  >
                    <Plus className="mr-1 h-3.5 w-3.5" /> New task
                  </Button>
                </div>
              </div>

              {error && (
                <Alert variant="destructive" className="mb-4">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              {/* Collapsible project settings (space badge + move/copy + members) */}
              {settingsOpen && (
                <Card className="mb-4">
                  <CardContent className="space-y-3 pt-6" data-testid="project-space-info">
                    {project.description && (
                      <p className="text-sm text-muted-foreground">{project.description}</p>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-xs text-muted-foreground">Space</span>
                      <Badge variant="secondary" className="gap-1">
                        {space?.isPersonal && <Lock className="h-3 w-3" aria-hidden />}
                        {space?.name ??
                          (typeof project.space === "string" ? "Unknown space" : project.space.name)}
                      </Badge>
                      {spaceId && (
                        <Button asChild variant="link" size="sm" className="h-auto p-0">
                          <Link href={`/spaces/${spaceId}`}>Manage members in space</Link>
                        </Button>
                      )}
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

              <Tabs defaultValue="tasks">
                <TabsList variant="line">
                  <TabsTrigger value="tasks">
                    Tasks{" "}
                    <span className="ml-1 text-xs text-muted-foreground">{openCount}</span>
                  </TabsTrigger>
                  <TabsTrigger value="activity">Activity</TabsTrigger>
                </TabsList>

                <TabsContent value="tasks" className="mt-4">
                  {tasks.length === 0 && !addOpen ? (
                    <Card>
                      <CardContent className="pt-6">
                        <p className="text-muted-foreground">
                          No tasks in this project yet.
                        </p>
                      </CardContent>
                    </Card>
                  ) : (
                    <div className="overflow-x-auto rounded-lg border">
                      <table className="w-full text-sm" data-testid="project-task-list">
                        <thead>
                          <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
                            <th className="w-8 px-3 py-2" />
                            <th className="w-10 px-1 py-2 text-left font-medium">#</th>
                            <th className="px-2 py-2 text-left font-medium">Task</th>
                            {definitions.map((def) => (
                              <th
                                key={def["@id"]}
                                className="px-2 py-2 text-left font-medium whitespace-nowrap"
                              >
                                {def.name}
                              </th>
                            ))}
                            <th className="px-2 py-2 text-left font-medium">Due</th>
                            <th className="px-2 py-2 text-left font-medium">Assignees</th>
                            <th className="w-10 px-2 py-2" />
                          </tr>
                        </thead>
                        <tbody>
                          {addOpen && (
                            <tr
                              className="border-b bg-muted/20"
                              data-testid="project-new-task-row"
                            >
                              <td className="px-3 py-2 align-middle">
                                <Plus
                                  className="h-4 w-4 text-muted-foreground"
                                  aria-hidden
                                />
                              </td>
                              <td className="px-1 py-2" />
                              <td className="px-2 py-2">
                                <Input
                                  ref={newTaskInputRef}
                                  value={newTaskTitle}
                                  onChange={(e) => setNewTaskTitle(e.target.value)}
                                  onKeyDown={(e) => {
                                    if (e.key === "Enter") {
                                      e.preventDefault();
                                      void createTask();
                                    } else if (e.key === "Escape") {
                                      e.preventDefault();
                                      closeAddRow();
                                    }
                                  }}
                                  onBlur={() => {
                                    if (!newTaskTitle.trim()) closeAddRow();
                                  }}
                                  placeholder="Task title, then Enter…"
                                  maxLength={255}
                                  disabled={isCreatingTask}
                                  className="h-8"
                                  data-testid="project-new-task-title"
                                />
                              </td>
                              <td
                                colSpan={definitions.length + 3}
                                className="px-2 py-2 text-xs text-muted-foreground"
                              >
                                Enter to add · Esc to cancel
                              </td>
                            </tr>
                          )}
                          {grouped.map((group) => (
                            <GroupRows
                              key={group.bucket}
                              label={BUCKET_LABELS[group.bucket]}
                              count={group.tasks.length}
                              colSpan={totalCols}
                              tasks={group.tasks}
                              definitions={definitions}
                              allTags={allTags}
                              assignableUsers={assignableUsers}
                              projectIri={project["@id"]}
                              spaceIri={projectSpaceIri(project)}
                              onToggle={toggleComplete}
                              onOpen={openTaskDetail}
                              patchTask={patchTask}
                              onCustomFieldChange={handleCustomFieldChange}
                            />
                          ))}
                        </tbody>
                      </table>
                      <CustomFieldFooterRow projectId={project.id} />
                    </div>
                  )}
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
        assignableUsers={assignableUsers}
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
      />
    </>
  );
};

interface GroupRowsProps {
  label: string;
  count: number;
  colSpan: number;
  tasks: ProjectTask[];
  definitions: CustomFieldDefinition[];
  allTags: TagOption[];
  assignableUsers: AssigneeOption[];
  projectIri: string;
  spaceIri: string;
  onToggle: (task: ProjectTask) => void;
  onOpen: (task: ProjectTask) => void;
  patchTask: (task: ProjectTask, body: Record<string, unknown>) => Promise<void>;
  onCustomFieldChange: (task: ProjectTask, defIri: string, value: unknown) => void;
}

const GroupRows = ({
  label,
  count,
  colSpan,
  tasks,
  definitions,
  allTags,
  assignableUsers,
  projectIri,
  spaceIri,
  onToggle,
  onOpen,
  patchTask,
  onCustomFieldChange,
}: GroupRowsProps) => (
  <>
    <tr className="border-b bg-muted/40">
      <td colSpan={colSpan} className="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {label} <span className="text-muted-foreground/60">{count}</span>
      </td>
    </tr>
    {tasks.map((task, i) => (
      <ProjectTaskRow
        key={task["@id"]}
        task={task}
        index={i}
        definitions={definitions}
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
  </>
);

const ProjectTaskRow = ({
  task,
  index,
  definitions,
  allTags,
  assignableUsers,
  projectIri,
  spaceIri,
  onToggle,
  onOpen,
  patchTask,
  onCustomFieldChange,
}: Omit<GroupRowsProps, "label" | "count" | "colSpan" | "tasks"> & {
  task: ProjectTask;
  index: number;
}) => {
  const [editingTitle, setEditingTitle] = useState(false);
  const [titleDraft, setTitleDraft] = useState(task.title);
  useEffect(() => {
    if (!editingTitle) setTitleDraft(task.title);
  }, [task.title, editingTitle]);

  const saveTitle = () => {
    const next = titleDraft.trim();
    setEditingTitle(false);
    if (!next || next === task.title) {
      setTitleDraft(task.title);
      return;
    }
    void patchTask(task, { title: next });
  };

  return (
    <tr
      className="border-b last:border-0 hover:bg-accent/40"
      data-testid="project-task-item"
    >
      <td className="px-3 py-2 align-top">
        <input
          type="checkbox"
          checked={!!task.completedOn}
          onChange={() => onToggle(task)}
          aria-label={`Mark "${task.title}" as ${task.completedOn ? "open" : "done"}`}
          className="mt-2 h-4 w-4 cursor-pointer"
        />
      </td>
      <td className="px-1 py-2 align-top text-xs text-muted-foreground">
        T{index + 1}
      </td>
      <td className="min-w-[12rem] px-2 py-2 align-top">
        {editingTitle ? (
          <Input
            autoFocus
            value={titleDraft}
            onChange={(e) => setTitleDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                saveTitle();
              } else if (e.key === "Escape") {
                e.preventDefault();
                setEditingTitle(false);
                setTitleDraft(task.title);
              }
            }}
            onBlur={saveTitle}
            maxLength={255}
            aria-label={`Edit title for "${task.title}"`}
            className="h-8"
            data-testid="project-task-title-input"
          />
        ) : (
          <button
            type="button"
            onClick={() => setEditingTitle(true)}
            className={cn(
              "w-full cursor-text text-left font-medium hover:text-primary",
              task.completedOn && "text-muted-foreground line-through",
            )}
            data-testid="project-task-title"
          >
            {task.title}
          </button>
        )}
        <div className="mt-1">
          <TagsCombobox
            value={task.tags}
            options={allTags}
            onChange={(iris) => void patchTask(task, { tags: iris })}
            subjectLabel={task.title}
          />
        </div>
      </td>
      {definitions.map((def) => (
        <td key={def["@id"]} className="px-2 py-2 align-top">
          <ProjectCustomFieldCell
            task={task}
            definition={def}
            projectIri={projectIri}
            spaceIri={spaceIri}
            users={assignableUsers}
            onCustomFieldChange={onCustomFieldChange}
          />
        </td>
      ))}
      <td className="px-2 py-2 align-top">
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
          onRemindersChange={(next) => void patchTask(task, { reminders: next })}
          status={dueDateStatus(task.dueDate, !!task.completedOn)}
        />
      </td>
      <td className="px-2 py-2 align-top">
        <AssigneesCombobox
          value={task.assignees}
          options={assignableUsers}
          onChange={(iris) => void patchTask(task, { assignees: iris })}
          subjectLabel={task.title}
        />
      </td>
      <td className="px-2 py-2 align-top">
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
