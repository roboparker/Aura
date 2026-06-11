import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { trackEvent } from "@/lib/analytics";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
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

const errorMessage = (err: unknown, fallback: string): string =>
  err instanceof Error ? err.message : fallback;

const Projects = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  // Bumped after a successful create so the MarkdownEditor remounts empty.
  const [editorResetKey, setEditorResetKey] = useState(0);

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");

  // Most recent mutation failure; query failures fall back to the query's
  // own error in `error` below.
  const [actionError, setActionError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  // Scope to the active space (#187). Falls back to an unfiltered GET while
  // the space list is still loading so the page doesn't flash empty on first
  // paint — the access extension caps the result set to the user's spaces
  // either way. react-query keys on the space so switching spaces refetches
  // (and caches each space's list).
  const spaceIri = activeSpace?.["@id"] ?? null;
  const projectsQuery = useQuery({
    queryKey: ["projects", spaceIri],
    enabled: isAuthenticated,
    queryFn: () =>
      apiGetCollection<Project>(
        spaceIri ? `/projects?space=${encodeURIComponent(spaceIri)}` : "/projects",
        { errorMessage: "Failed to load projects." },
      ),
  });
  const projects = projectsQuery.data ?? [];
  const refreshProjects = () => queryClient.invalidateQueries({ queryKey: ["projects"] });

  const createMutation = useMutation({
    mutationFn: () =>
      apiSend("POST", "/projects", {
        errorMessage: "Failed to create project.",
        body: {
          title: title.trim(),
          description: description.trim() || null,
          // Pin the new project to the active space (#187) so work created
          // in a shared space doesn't silently land in the personal one.
          ...(spaceIri ? { space: spaceIri } : {}),
        },
      }),
    onSuccess: () => {
      setTitle("");
      setDescription("");
      setEditorResetKey((k) => k + 1);
      setActionError(null);
      trackEvent("project-create");
      void refreshProjects();
    },
    onError: (err) => setActionError(errorMessage(err, "Failed to create project.")),
  });

  const updateMutation = useMutation({
    mutationFn: (project: Project) =>
      apiSend("PATCH", project["@id"], {
        contentType: "application/merge-patch+json",
        errorMessage: "Failed to update project.",
        body: {
          title: editTitle.trim(),
          description: editDescription.trim() || null,
        },
      }),
    onSuccess: () => {
      setEditingId(null);
      setActionError(null);
      void refreshProjects();
    },
    onError: (err) => setActionError(errorMessage(err, "Failed to update project.")),
  });

  const deleteMutation = useMutation({
    mutationFn: (project: Project) =>
      apiSend("DELETE", project["@id"], { errorMessage: "Failed to delete project." }),
    onSuccess: () => {
      setActionError(null);
      void refreshProjects();
    },
    onError: (err) => setActionError(errorMessage(err, "Failed to delete project.")),
  });

  const error =
    actionError ??
    (projectsQuery.isError ? errorMessage(projectsQuery.error, "Failed to load projects.") : null);

  const handleCreate = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!title.trim()) return;
    createMutation.mutate();
  };

  const startEdit = (project: Project) => {
    setEditingId(project["@id"]);
    setEditTitle(project.title);
    setEditDescription(project.description ?? "");
  };

  const cancelEdit = () => {
    setEditingId(null);
  };

  const handleUpdate = (event: FormEvent<HTMLFormElement>, project: Project) => {
    event.preventDefault();
    if (!editTitle.trim()) return;
    updateMutation.mutate(project);
  };

  const handleDelete = (project: Project) => {
    // Deleting a project deletes it for every member, and its tasks revert to
    // personal (project_id SET NULL). Make sure the user is aware.
    if (
      !window.confirm(
        `Delete project "${project.title}"? This removes it for all members; project tasks become personal tasks of their owners.`,
      )
    ) {
      return;
    }
    deleteMutation.mutate(project);
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
                <Button type="submit" disabled={createMutation.isPending || !title.trim()}>
                  {createMutation.isPending ? "Adding..." : "Add Project"}
                </Button>
              </form>
            </CardContent>
          </Card>

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {projectsQuery.isLoading ? (
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
