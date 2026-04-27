import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
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
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading...</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Projects - Aura</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <h1 className="text-2xl font-bold mb-6">Projects</h1>

          <Card className="mb-6">
            <CardContent className="pt-6">
              <form onSubmit={handleCreate} className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="title">Title</Label>
                  <Input
                    id="title"
                    type="text"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    required
                    maxLength={255}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="description">
                    Description{" "}
                    <span className="text-muted-foreground font-normal">(optional)</span>
                  </Label>
                  <MarkdownEditor
                    key={editorResetKey}
                    id="description"
                    ariaLabel="Description"
                    value={description}
                    onChange={setDescription}
                  />
                </div>
                <Button type="submit" disabled={isSubmitting || !title.trim()}>
                  {isSubmitting ? "Adding..." : "Add Project"}
                </Button>
              </form>
            </CardContent>
          </Card>

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {isLoading ? (
            <p className="text-muted-foreground">Loading projects...</p>
          ) : projects.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">
                  No projects yet. Create one above to start collaborating.
                </p>
              </CardContent>
            </Card>
          ) : (
            <ul className="space-y-2" data-testid="project-list">
              {projects.map((project) => (
                <li key={project["@id"]} data-testid="project-item">
                  <Card>
                    <CardContent className="pt-4 pb-4">
                      {editingId === project["@id"] ? (
                        <form onSubmit={(e) => handleUpdate(e, project)} className="space-y-3">
                          <Input
                            type="text"
                            value={editTitle}
                            onChange={(e) => setEditTitle(e.target.value)}
                            required
                            maxLength={255}
                            aria-label="Title"
                          />
                          <MarkdownEditor
                            ariaLabel="Description"
                            value={editDescription}
                            onChange={setEditDescription}
                          />
                          <div className="flex gap-2">
                            <Button type="submit" size="sm">
                              Save
                            </Button>
                            <Button
                              type="button"
                              variant="secondary"
                              size="sm"
                              onClick={cancelEdit}
                            >
                              Cancel
                            </Button>
                          </div>
                        </form>
                      ) : (
                        <div>
                          <div className="flex items-start justify-between gap-3">
                            <h2 className="font-semibold">
                              <Link
                                href={`/projects/${project.id}`}
                                className="text-primary hover:underline no-underline"
                              >
                                {project.title}
                              </Link>
                            </h2>
                            <div className="flex items-center gap-1 shrink-0">
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => startEdit(project)}
                              >
                                Edit
                              </Button>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => handleDelete(project)}
                                aria-label={`Delete "${project.title}"`}
                                className="text-destructive hover:text-destructive"
                              >
                                Delete
                              </Button>
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
                              <span className="text-xs text-muted-foreground">Members:</span>
                              {project.members.map((member) => (
                                <Badge
                                  key={member["@id"]}
                                  variant="secondary"
                                  data-testid="project-member"
                                >
                                  {member.email}
                                </Badge>
                              ))}
                            </div>
                          )}
                        </div>
                      )}
                    </CardContent>
                  </Card>
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
