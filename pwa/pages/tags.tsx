import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/contexts/AuthContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

interface Tag {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  color: string;
}

const DEFAULT_COLOR = "#6b7280";

const errorMessage = (err: unknown, fallback: string): string =>
  err instanceof Error ? err.message : fallback;

const Tags = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [color, setColor] = useState(DEFAULT_COLOR);
  // Bumped after a successful create so the MarkdownEditor remounts empty.
  const [editorResetKey, setEditorResetKey] = useState(0);

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [editColor, setEditColor] = useState(DEFAULT_COLOR);

  // Most recent mutation failure; query failures fall back to the query's
  // own error in `error` below.
  const [actionError, setActionError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const tagsQuery = useQuery({
    queryKey: ["tags"],
    enabled: isAuthenticated,
    queryFn: () => apiGetCollection<Tag>("/tags", { errorMessage: "Failed to load tags." }),
  });
  const tags = tagsQuery.data ?? [];
  const refreshTags = () => queryClient.invalidateQueries({ queryKey: ["tags"] });

  const createMutation = useMutation({
    mutationFn: () =>
      apiSend("POST", "/tags", {
        errorMessage: "Failed to create tag.",
        body: { title: title.trim(), description: description.trim() || null, color },
      }),
    onSuccess: () => {
      setTitle("");
      setDescription("");
      setColor(DEFAULT_COLOR);
      setEditorResetKey((k) => k + 1);
      setActionError(null);
      void refreshTags();
    },
    onError: (err) => setActionError(errorMessage(err, "Failed to create tag.")),
  });

  const updateMutation = useMutation({
    mutationFn: (tag: Tag) =>
      apiSend("PATCH", tag["@id"], {
        contentType: "application/merge-patch+json",
        errorMessage: "Failed to update tag.",
        body: {
          title: editTitle.trim(),
          description: editDescription.trim() || null,
          color: editColor,
        },
      }),
    onSuccess: () => {
      setEditingId(null);
      setActionError(null);
      void refreshTags();
    },
    onError: (err) => setActionError(errorMessage(err, "Failed to update tag.")),
  });

  const deleteMutation = useMutation({
    mutationFn: (tag: Tag) => apiSend("DELETE", tag["@id"], { errorMessage: "Failed to delete tag." }),
    onSuccess: () => {
      setActionError(null);
      void refreshTags();
    },
    onError: (err) => setActionError(errorMessage(err, "Failed to delete tag.")),
  });

  const error =
    actionError ?? (tagsQuery.isError ? errorMessage(tagsQuery.error, "Failed to load tags.") : null);

  const handleCreate = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!title.trim()) return;
    createMutation.mutate();
  };

  const startEdit = (tag: Tag) => {
    setEditingId(tag["@id"]);
    setEditTitle(tag.title);
    setEditDescription(tag.description ?? "");
    setEditColor(tag.color);
  };

  const cancelEdit = () => {
    setEditingId(null);
  };

  const handleUpdate = (event: FormEvent<HTMLFormElement>, tag: Tag) => {
    event.preventDefault();
    if (!editTitle.trim()) return;
    updateMutation.mutate(tag);
  };

  const handleDelete = (tag: Tag) => {
    if (!window.confirm(`Delete tag "${tag.title}"? It will be removed from any tasks using it.`)) {
      return;
    }
    deleteMutation.mutate(tag);
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
        <title>Tags - Aura</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <h1 className="text-2xl font-bold mb-6">Tags</h1>

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
                    maxLength={100}
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
                <div className="space-y-1.5">
                  <Label htmlFor="color">Color</Label>
                  <div className="flex items-center gap-3">
                    <input
                      id="color"
                      type="color"
                      value={color}
                      onChange={(e) => setColor(e.target.value)}
                      className="h-10 w-16 border border-input rounded-md cursor-pointer"
                    />
                    <span className="text-sm font-mono text-muted-foreground">{color}</span>
                  </div>
                </div>
                <Button type="submit" disabled={createMutation.isPending || !title.trim()}>
                  {createMutation.isPending ? "Adding..." : "Add Tag"}
                </Button>
              </form>
            </CardContent>
          </Card>

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {tagsQuery.isLoading ? (
            <p className="text-muted-foreground">Loading tags...</p>
          ) : tags.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">
                  No tags yet. Create one above to start organizing your tasks.
                </p>
              </CardContent>
            </Card>
          ) : (
            <ul className="space-y-2" data-testid="tag-list">
              {tags.map((tag) => (
                <li key={tag["@id"]} data-testid="tag-item">
                  <Card>
                    <CardContent className="pt-4 pb-4">
                      {editingId === tag["@id"] ? (
                        <form onSubmit={(e) => handleUpdate(e, tag)} className="space-y-3">
                          <Input
                            type="text"
                            value={editTitle}
                            onChange={(e) => setEditTitle(e.target.value)}
                            required
                            maxLength={100}
                            aria-label="Title"
                          />
                          <MarkdownEditor
                            ariaLabel="Description"
                            value={editDescription}
                            onChange={setEditDescription}
                          />
                          <div className="flex items-center gap-3">
                            <input
                              type="color"
                              value={editColor}
                              onChange={(e) => setEditColor(e.target.value)}
                              aria-label="Color"
                              className="h-10 w-16 border border-input rounded-md cursor-pointer"
                            />
                            <span className="text-sm font-mono text-muted-foreground">
                              {editColor}
                            </span>
                          </div>
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
                        <div className="flex items-start gap-3">
                          <span
                            className="inline-block mt-1 px-2 py-0.5 rounded text-xs font-semibold text-white shrink-0"
                            style={{ backgroundColor: tag.color }}
                          >
                            {tag.title}
                          </span>
                          <div className="flex-1 min-w-0">
                            {tag.description && <MarkdownView source={tag.description} />}
                          </div>
                          <Button variant="ghost" size="sm" onClick={() => startEdit(tag)}>
                            Edit
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => handleDelete(tag)}
                            aria-label={`Delete "${tag.title}"`}
                            className="text-destructive hover:text-destructive"
                          >
                            Delete
                          </Button>
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

export default Tags;
