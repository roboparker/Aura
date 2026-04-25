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

interface ProjectCollection {
  member?: Project[];
  "hydra:member"?: Project[];
}

const Projects = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [projects, setProjects] = useState<Project[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  // Bumped after a successful create so the MarkdownEditor remounts empty.
  const [editorResetKey, setEditorResetKey] = useState(0);

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push("/signin");
    }
  }, [authLoading, isAuthenticated, router]);

  const loadProjects = useCallback(async () => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/projects`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) {
        throw new Error("Failed to load projects.");
      }
      const data: ProjectCollection = await res.json();
      setProjects(data.member ?? data["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load projects.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) {
      loadProjects();
    }
  }, [isAuthenticated, loadProjects]);

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!title.trim()) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/projects`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          title: title.trim(),
          description: description.trim() || null,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to create project.",
        );
      }
      setTitle("");
      setDescription("");
      setEditorResetKey((k) => k + 1);
      await loadProjects();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create project.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const startEdit = (project: Project) => {
    setEditingId(project["@id"]);
    setEditTitle(project.title);
    setEditDescription(project.description ?? "");
  };

  const cancelEdit = () => {
    setEditingId(null);
  };

  const handleUpdate = async (event: FormEvent<HTMLFormElement>, project: Project) => {
    event.preventDefault();
    if (!editTitle.trim()) return;

    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${project["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          title: editTitle.trim(),
          description: editDescription.trim() || null,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to update project.",
        );
      }
      setEditingId(null);
      await loadProjects();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update project.");
    }
  };

  const handleDelete = async (project: Project) => {
    // Deleting a project deletes it for every member, and its tasks revert to
    // personal (project_id SET NULL). Make sure the user is aware.
    if (
      !window.confirm(
        `Delete project "${project.title}"? This removes it for all members; project tasks become personal tasks of their owners.`,
      )
    ) {
      return;
    }

    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${project["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) {
        throw new Error("Failed to delete project.");
      }
      await loadProjects();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete project.");
    }
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <p className="text-gray-500">Loading...</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Projects - Aura</title>
      </Head>
      <div className="min-h-screen bg-gray-50 px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <h1 className="text-2xl font-bold text-black mb-6">Projects</h1>

          <form
            onSubmit={handleCreate}
            className="bg-white rounded-lg shadow-card p-6 mb-6 space-y-4"
          >
            <div>
              <label htmlFor="title" className="block text-sm font-medium text-gray-700">
                Title
              </label>
              <input
                id="title"
                type="text"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                required
                maxLength={255}
                className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-500"
              />
            </div>
            <div>
              <label htmlFor="description" className="block text-sm font-medium text-gray-700 mb-1">
                Description <span className="text-gray-400">(optional)</span>
              </label>
              <MarkdownEditor
                key={editorResetKey}
                id="description"
                ariaLabel="Description"
                value={description}
                onChange={setDescription}
              />
            </div>
            <button
              type="submit"
              disabled={isSubmitting || !title.trim()}
              className="bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isSubmitting ? "Adding..." : "Add Project"}
            </button>
          </form>

          {error && (
            <div
              role="alert"
              className="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded"
            >
              {error}
            </div>
          )}

          {isLoading ? (
            <p className="text-gray-500">Loading projects...</p>
          ) : projects.length === 0 ? (
            <p className="text-gray-500 bg-white rounded-lg shadow-card p-6">
              No projects yet. Create one above to start collaborating.
            </p>
          ) : (
            <ul className="space-y-2" data-testid="project-list">
              {projects.map((project) => (
                <li
                  key={project["@id"]}
                  className="bg-white rounded-lg shadow-card p-4"
                  data-testid="project-item"
                >
                  {editingId === project["@id"] ? (
                    <form onSubmit={(e) => handleUpdate(e, project)} className="space-y-3">
                      <input
                        type="text"
                        value={editTitle}
                        onChange={(e) => setEditTitle(e.target.value)}
                        required
                        maxLength={255}
                        aria-label="Title"
                        className="block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-500"
                      />
                      <MarkdownEditor
                        ariaLabel="Description"
                        value={editDescription}
                        onChange={setEditDescription}
                      />
                      <div className="flex gap-2">
                        <button
                          type="submit"
                          className="bg-cyan-700 text-white py-1 px-3 rounded-md text-sm font-semibold hover:bg-cyan-800"
                        >
                          Save
                        </button>
                        <button
                          type="button"
                          onClick={cancelEdit}
                          className="bg-gray-200 text-gray-700 py-1 px-3 rounded-md text-sm hover:bg-gray-300"
                        >
                          Cancel
                        </button>
                      </div>
                    </form>
                  ) : (
                    <div>
                      <div className="flex items-start justify-between gap-3">
                        <h2 className="font-semibold text-black">
                          <Link
                            href={`/projects/${project.id}`}
                            className="text-cyan-700 hover:text-cyan-900 no-underline"
                          >
                            {project.title}
                          </Link>
                        </h2>
                        <div className="flex items-center gap-3 shrink-0">
                          <button
                            onClick={() => startEdit(project)}
                            className="text-cyan-700 hover:text-cyan-900 text-sm font-medium bg-transparent border-0 cursor-pointer"
                          >
                            Edit
                          </button>
                          <button
                            onClick={() => handleDelete(project)}
                            aria-label={`Delete "${project.title}"`}
                            className="text-red-600 hover:text-red-700 text-sm font-medium bg-transparent border-0 cursor-pointer"
                          >
                            Delete
                          </button>
                        </div>
                      </div>
                      {project.description && (
                        <MarkdownView source={project.description} className="mt-1" />
                      )}
                      {project.members.length > 0 && (
                        <div
                          className="mt-2 flex flex-wrap items-center gap-1"
                          data-testid="project-members"
                        >
                          <span className="text-xs text-gray-500">Members:</span>
                          {project.members.map((member) => (
                            <span
                              key={member["@id"]}
                              className="inline-block px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-700"
                              data-testid="project-member"
                            >
                              {member.email}
                            </span>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </>
  );
};

export default Projects;
