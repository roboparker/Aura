import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { useAuth } from "../../contexts/AuthContext";
import { ENTRYPOINT } from "../../config/entrypoint";
import MarkdownEditor from "../../components/editor/MarkdownEditor";
import MarkdownView from "../../components/editor/MarkdownView";

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
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <p className="text-gray-500">Loading...</p>
      </div>
    );
  }

  if (notFound) {
    return (
      <div className="min-h-screen bg-gray-50 px-4 py-12">
        <div className="max-w-2xl mx-auto bg-white rounded-lg shadow-card p-6">
          <h1 className="text-xl font-bold text-black mb-2">Project not found</h1>
          <p className="text-gray-600 mb-4">
            It may have been deleted, or you may not be a member.
          </p>
          <Link href="/projects" className="text-cyan-700 font-medium">
            Back to projects
          </Link>
        </div>
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
      <div className="min-h-screen bg-gray-50 px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <Link
            href="/projects"
            className="inline-block text-sm text-cyan-700 hover:text-cyan-900 mb-3 no-underline"
          >
            ← All projects
          </Link>

          {isLoading || !project ? (
            <p className="text-gray-500">Loading project...</p>
          ) : (
            <>
              <div className="bg-white rounded-lg shadow-card p-6 mb-6">
                <h1 className="text-2xl font-bold text-black mb-2">{project.title}</h1>
                {project.description && (
                  <MarkdownView source={project.description} className="mb-3" />
                )}

                <div className="mt-3">
                  <p className="text-xs text-gray-500 mb-1">Members</p>
                  <ul className="flex flex-wrap items-center gap-1" data-testid="member-list">
                    {project.members.map((member) => (
                      <li
                        key={member["@id"]}
                        className="inline-flex items-center gap-1 bg-gray-100 text-gray-700 text-xs px-2 py-0.5 rounded"
                        data-testid="member-pill"
                      >
                        <span>{member.email}</span>
                        <button
                          type="button"
                          onClick={() => handleRemoveMember(member)}
                          aria-label={`Remove ${member.email}`}
                          className="ml-1 text-gray-500 hover:text-red-600 leading-none bg-transparent border-0 cursor-pointer text-base"
                        >
                          ×
                        </button>
                      </li>
                    ))}
                  </ul>

                  <form
                    onSubmit={handleAddMember}
                    className="mt-2 flex items-center gap-2"
                    data-testid="add-member-form"
                  >
                    <input
                      type="email"
                      value={newMemberEmail}
                      onChange={(e) => setNewMemberEmail(e.target.value)}
                      placeholder="member@example.com"
                      aria-label="New member email"
                      required
                      className="flex-1 border border-gray-300 rounded-md px-3 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500"
                    />
                    <button
                      type="submit"
                      disabled={isAddingMember || !newMemberEmail.trim()}
                      className="bg-cyan-700 text-white py-1 px-3 rounded-md text-sm font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                      {isAddingMember ? "Adding..." : "Add"}
                    </button>
                  </form>
                  {memberError && (
                    <p role="alert" className="mt-2 text-sm text-red-600">
                      {memberError}
                    </p>
                  )}
                </div>
              </div>

              {error && (
                <div
                  role="alert"
                  className="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded"
                >
                  {error}
                </div>
              )}

              <form
                onSubmit={handleCreateTask}
                className="bg-white rounded-lg shadow-card p-6 mb-6 space-y-4"
                data-testid="create-task-form"
              >
                <h2 className="text-lg font-semibold text-black">Add a task</h2>
                <div>
                  <label htmlFor="task-title" className="block text-sm font-medium text-gray-700">
                    Title
                  </label>
                  <input
                    id="task-title"
                    type="text"
                    value={newTaskTitle}
                    onChange={(e) => setNewTaskTitle(e.target.value)}
                    required
                    maxLength={255}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-500"
                  />
                </div>
                <div>
                  <label htmlFor="task-description" className="block text-sm font-medium text-gray-700 mb-1">
                    Description <span className="text-gray-400">(optional)</span>
                  </label>
                  <MarkdownEditor
                    key={taskEditorKey}
                    id="task-description"
                    ariaLabel="Task description"
                    value={newTaskDescription}
                    onChange={setNewTaskDescription}
                  />
                </div>
                <button
                  type="submit"
                  disabled={isCreatingTask || !newTaskTitle.trim()}
                  className="bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {isCreatingTask ? "Adding..." : "Add Task"}
                </button>
              </form>

              <h2 className="text-lg font-semibold text-black mb-2">
                Tasks{" "}
                <span className="text-sm font-normal text-gray-500">
                  ({openTasks.length} open
                  {completedTasks.length > 0 ? `, ${completedTasks.length} done` : ""})
                </span>
              </h2>

              {tasks.length === 0 ? (
                <p className="text-gray-500 bg-white rounded-lg shadow-card p-6">
                  No tasks in this project yet.
                </p>
              ) : (
                <ul className="space-y-2" data-testid="project-task-list">
                  {[...openTasks, ...completedTasks].map((task) => (
                    <li
                      key={task["@id"]}
                      className="bg-white rounded-lg shadow-card p-4 flex gap-3"
                      data-testid="project-task-item"
                    >
                      <input
                        type="checkbox"
                        checked={!!task.completedOn}
                        onChange={() => toggleComplete(task)}
                        aria-label={`Mark "${task.title}" as ${task.completedOn ? "open" : "done"}`}
                        className="mt-1 h-4 w-4 shrink-0 cursor-pointer"
                      />
                      <div className="min-w-0 flex-1">
                        <div
                          className={`font-medium ${
                            task.completedOn ? "text-gray-400 line-through" : "text-black"
                          }`}
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
