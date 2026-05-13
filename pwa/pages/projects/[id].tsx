import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Lock } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import ActivityPanel from "@/components/activity/ActivityPanel";
import CustomFieldFooterRow from "@/components/custom-fields/CustomFieldFooterRow";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
  // Members read-only from the API as a virtual getter; kept here so
  // the page can render member chips without a follow-up fetch. Mutation
  // happens via the space (#187) — see the "Manage in space" link
  // surfaced below.
  members: Member[];
  // Server returns the parent space embedded under `project:read`;
  // PWA falls back to looking it up by IRI in the user's space list
  // for the admin role check.
  space: string | SpaceRef;
}

interface Tag {
  "@id": string;
  id: string;
  title: string;
  color: string;
}

interface Task {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  completedOn: string | null;
  position: number;
  tags: Tag[];
}

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

const projectSpaceIri = (project: Project): string =>
  typeof project.space === "string" ? project.space : project.space["@id"];

const ProjectDetail = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { spaces } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const projectId = typeof id === "string" ? id : null;

  const [project, setProject] = useState<Project | null>(null);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Task creation
  const [newTaskTitle, setNewTaskTitle] = useState("");
  const [newTaskDescription, setNewTaskDescription] = useState("");
  const [isCreatingTask, setIsCreatingTask] = useState(false);
  const [taskEditorKey, setTaskEditorKey] = useState(0);

  // Move-to-space (#182)
  const [moveTargetIri, setMoveTargetIri] = useState("");
  const [isMoving, setIsMoving] = useState(false);
  const [moveMessage, setMoveMessage] = useState<{
    text: string;
    kind: "success" | "error";
  } | null>(null);

  // Copy-to-space (#182). Reuses the same target picker but POSTs to
  // /copy instead of /move. On success we navigate to the new
  // project's detail page so the user lands on the clone.
  const [isCopying, setIsCopying] = useState(false);
  // Opt-in deep-copy: when true, the POST body asks the server to
  // also clone the project's tasks (#182 deep-copy slice).
  const [copyIncludeTasks, setCopyIncludeTasks] = useState(false);

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
      const projectRes = await fetch(`${ENTRYPOINT}/projects/${encodeURIComponent(projectId)}`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (projectRes.status === 404 || projectRes.status === 403) {
        setNotFound(true);
        return;
      }
      if (!projectRes.ok) throw new Error("Failed to load project.");
      const projectData: Project = await projectRes.json();
      setProject(projectData);

      const tasksRes = await fetch(
        `${ENTRYPOINT}/tasks?project=${encodeURIComponent(projectData["@id"])}`,
        {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        },
      );
      if (!tasksRes.ok) throw new Error("Failed to load tasks.");
      const tasksData: Collection<Task> = await tasksRes.json();
      setTasks(tasksData.member ?? tasksData["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load project.");
    } finally {
      setIsLoading(false);
    }
  }, [projectId]);

  useEffect(() => {
    if (isAuthenticated && projectId) {
      load();
    }
  }, [isAuthenticated, projectId, load]);

  const toggleComplete = async (task: Task) => {
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
      const updated: Task = await res.json();
      setTasks((prev) => prev.map((t) => (t["@id"] === task["@id"] ? updated : t)));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update task.");
    }
  };

  const handleCreateTask = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!project || !newTaskTitle.trim()) return;

    setIsCreatingTask(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/tasks`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          title: newTaskTitle.trim(),
          description: newTaskDescription.trim() || null,
          project: project["@id"],
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to create task.",
        );
      }
      const created: Task = await res.json();
      setTasks((prev) => [...prev, created]);
      setNewTaskTitle("");
      setNewTaskDescription("");
      setTaskEditorKey((k) => k + 1);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create task.");
    } finally {
      setIsCreatingTask(false);
    }
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
      // Empty body = clone into the source's own space; specifying a
      // target uses the picker's current selection. The server accepts
      // both shapes. `includeTasks` is the only opt-in deep-copy flag —
      // discussions live at the space level and are never carried.
      const body: {
        space?: string;
        includeTasks?: boolean;
      } = {};
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
      // Land the user on the freshly-cloned project so they can rename
      // it or start filling it in.
      if (data.id) {
        await router.push(`/projects/${data.id}`);
      }
    } catch (err) {
      setMoveMessage({
        text: err instanceof Error ? err.message : "Failed to copy project.",
        kind: "error",
      });
    } finally {
      setIsCopying(false);
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

  const openTasks = tasks.filter((t) => !t.completedOn);
  const completedTasks = tasks.filter((t) => t.completedOn);

  return (
    <>
      <Head>
        <title>{project ? `${project.title} - Aura` : "Project - Aura"}</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <Link
            href="/projects"
            className="inline-block text-sm text-primary hover:underline mb-3 no-underline"
          >
            ← All projects
          </Link>

          {isLoading || !project ? (
            <p className="text-muted-foreground">Loading project...</p>
          ) : (
            <>
              <Card className="mb-6">
                <CardContent className="pt-6">
                  <h1 className="text-2xl font-bold mb-2">{project.title}</h1>
                  {project.description && (
                    <MarkdownView source={project.description} className="mb-3" />
                  )}

                  <div className="mt-3 space-y-2" data-testid="project-space-info">
                    {(() => {
                      const space = spaces.find(
                        (s) => s["@id"] === projectSpaceIri(project),
                      );
                      const spaceLabel =
                        typeof project.space === "string"
                          ? null
                          : project.space.name;
                      const spaceId =
                        typeof project.space === "string"
                          ? project.space.split("/").pop()
                          : project.space.id;
                      const isPersonal = space?.isPersonal ?? false;
                      return (
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-xs text-muted-foreground">
                            Space
                          </span>
                          <Badge variant="secondary" className="gap-1">
                            {isPersonal && (
                              <Lock className="h-3 w-3" aria-hidden />
                            )}
                            {space?.name ?? spaceLabel ?? "Unknown space"}
                          </Badge>
                          {spaceId && (
                            <Button asChild variant="link" size="sm" className="h-auto p-0">
                              <Link href={`/spaces/${spaceId}`}>
                                Manage members in space
                              </Link>
                            </Button>
                          )}
                        </div>
                      );
                    })()}

                    {/* Move / Copy (#182). Showing every space the
                        caller belongs to — server enforces the source
                        + target membership rule. Move is disabled
                        when there's nowhere else to go; Copy works
                        with no target (clones into the current
                        space). */}
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
                          title={
                            moveTargetIri
                              ? "Copy this project into the selected space"
                              : "Copy this project into its current space"
                          }
                        >
                          {isCopying ? "Copying…" : "Copy"}
                        </Button>
                        {/* Deep-copy opt-in. Hidden until the user
                            actually intends to copy — Move ignores it. */}
                        <label
                          className="flex items-center gap-1.5 text-xs text-muted-foreground select-none"
                          data-testid="project-copy-include-tasks-label"
                        >
                          <input
                            type="checkbox"
                            checked={copyIncludeTasks}
                            onChange={(e) =>
                              setCopyIncludeTasks(e.target.checked)
                            }
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
                  </div>
                </CardContent>
              </Card>

              {error && (
                <Alert variant="destructive" className="mb-4">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <div className="mb-6 flex flex-wrap justify-end gap-2">
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
              </div>

              <div className="space-y-6">
              <Card>
                <CardContent className="pt-6">
                  <form
                    onSubmit={handleCreateTask}
                    className="space-y-4"
                    data-testid="create-task-form"
                  >
                    <h2 className="text-lg font-semibold">Add a task</h2>
                    <div className="space-y-1.5">
                      <Label htmlFor="task-title">Title</Label>
                      <Input
                        id="task-title"
                        type="text"
                        value={newTaskTitle}
                        onChange={(e) => setNewTaskTitle(e.target.value)}
                        required
                        maxLength={255}
                      />
                    </div>
                    <div className="space-y-1.5">
                      <Label htmlFor="task-description">
                        Description{" "}
                        <span className="text-muted-foreground font-normal">(optional)</span>
                      </Label>
                      <MarkdownEditor
                        key={taskEditorKey}
                        id="task-description"
                        ariaLabel="Task description"
                        value={newTaskDescription}
                        onChange={setNewTaskDescription}
                      />
                    </div>
                    <Button type="submit" disabled={isCreatingTask || !newTaskTitle.trim()}>
                      {isCreatingTask ? "Adding..." : "Add Task"}
                    </Button>
                  </form>
                </CardContent>
              </Card>

              <h2 className="text-lg font-semibold mb-2">
                Tasks{" "}
                <span className="text-sm font-normal text-muted-foreground">
                  ({openTasks.length} open
                  {completedTasks.length > 0 ? `, ${completedTasks.length} done` : ""})
                </span>
              </h2>

              {tasks.length === 0 ? (
                <Card>
                  <CardContent className="pt-6">
                    <p className="text-muted-foreground">No tasks in this project yet.</p>
                  </CardContent>
                </Card>
              ) : (
                <ul className="space-y-2" data-testid="project-task-list">
                  {[...openTasks, ...completedTasks].map((task) => (
                    <li key={task["@id"]} data-testid="project-task-item">
                      <Card>
                        <CardContent className="pt-4 pb-4 flex gap-3">
                          <input
                            type="checkbox"
                            checked={!!task.completedOn}
                            onChange={() => toggleComplete(task)}
                            aria-label={`Mark "${task.title}" as ${task.completedOn ? "open" : "done"}`}
                            className="mt-1 h-4 w-4 shrink-0 cursor-pointer"
                          />
                          <div className="min-w-0 flex-1">
                            <div
                              className={cn(
                                "font-medium",
                                task.completedOn && "text-muted-foreground line-through",
                              )}
                            >
                              {task.title}
                            </div>
                            {task.description && (
                              <MarkdownView source={task.description} className="mt-1 text-sm" />
                            )}
                            {task.tags.length > 0 && (
                              <div className="mt-2 flex flex-wrap gap-1">
                                {task.tags.map((tag) => (
                                  <span
                                    key={tag["@id"]}
                                    className="inline-block px-2 py-0.5 rounded text-xs text-white"
                                    style={{ backgroundColor: tag.color }}
                                  >
                                    {tag.title}
                                  </span>
                                ))}
                              </div>
                            )}
                          </div>
                        </CardContent>
                      </Card>
                    </li>
                  ))}
                </ul>
              )}
              <CustomFieldFooterRow projectId={project.id} />
              </div>

              <div className="mt-6">
                <ActivityPanel endpoint={`/projects/${project.id}/activity`} />
              </div>
            </>
          )}
        </div>
      </div>
    </>
  );
};

export default ProjectDetail;
