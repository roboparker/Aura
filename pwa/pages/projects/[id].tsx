import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { X } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
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

interface Project {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  owner: Member;
  members: Member[];
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

const ProjectDetail = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
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

  // Member add
  const [newMemberEmail, setNewMemberEmail] = useState("");
  const [isAddingMember, setIsAddingMember] = useState(false);
  const [memberError, setMemberError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push("/signin");
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    if (!projectId) return;
    setError(null);
    setIsLoading(true);
    try {
      const projectRes = await fetch(`${ENTRYPOINT}/projects/${projectId}`, {
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

  const handleAddMember = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!project || !newMemberEmail.trim()) return;

    setIsAddingMember(true);
    setMemberError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/projects/${project.id}/members`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: newMemberEmail.trim() }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to add member.");
      }
      setNewMemberEmail("");
      await load();
    } catch (err) {
      setMemberError(err instanceof Error ? err.message : "Failed to add member.");
    } finally {
      setIsAddingMember(false);
    }
  };

  const handleRemoveMember = async (member: Member) => {
    if (!project) return;
    if (
      !window.confirm(
        member.email === user?.email
          ? "Remove yourself from this project? You'll lose access to its tasks."
          : `Remove ${member.email} from this project?`,
      )
    ) {
      return;
    }

    setMemberError(null);
    try {
      const remaining = project.members
        .filter((m) => m["@id"] !== member["@id"])
        .map((m) => m["@id"]);
      const res = await fetch(`${ENTRYPOINT}${project["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ members: remaining }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.detail || data["hydra:description"] || "Failed to remove member.");
      }
      // If you removed yourself you no longer have access; bounce to the list.
      if (member.email === user?.email) {
        router.push("/projects");
        return;
      }
      await load();
    } catch (err) {
      setMemberError(err instanceof Error ? err.message : "Failed to remove member.");
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

                  <div className="mt-3">
                    <p className="text-xs text-muted-foreground mb-1">Members</p>
                    <ul className="flex flex-wrap items-center gap-1" data-testid="member-list">
                      {project.members.map((member) => (
                        <li key={member["@id"]} data-testid="member-pill">
                          <Badge variant="secondary" className="gap-1">
                            <span>{member.email}</span>
                            <button
                              type="button"
                              onClick={() => handleRemoveMember(member)}
                              aria-label={`Remove ${member.email}`}
                              className="ml-0.5 text-muted-foreground hover:text-destructive bg-transparent border-0 cursor-pointer"
                            >
                              <X className="h-3 w-3" />
                            </button>
                          </Badge>
                        </li>
                      ))}
                    </ul>

                    <form
                      onSubmit={handleAddMember}
                      className="mt-2 flex items-center gap-2"
                      data-testid="add-member-form"
                    >
                      <Input
                        type="email"
                        value={newMemberEmail}
                        onChange={(e) => setNewMemberEmail(e.target.value)}
                        placeholder="member@example.com"
                        aria-label="New member email"
                        required
                        className="flex-1"
                      />
                      <Button
                        type="submit"
                        size="sm"
                        disabled={isAddingMember || !newMemberEmail.trim()}
                      >
                        {isAddingMember ? "Adding..." : "Add"}
                      </Button>
                    </form>
                    {memberError && (
                      <p role="alert" className="mt-2 text-sm text-destructive">
                        {memberError}
                      </p>
                    )}
                  </div>
                </CardContent>
              </Card>

              {error && (
                <Alert variant="destructive" className="mb-4">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <Card className="mb-6">
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
            </>
          )}
        </div>
      </div>
    </>
  );
};

export default ProjectDetail;
