import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
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

interface TagCollection {
  member?: Tag[];
  "hydra:member"?: Tag[];
}

const DEFAULT_COLOR = "#6b7280";

const Tags = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [tags, setTags] = useState<Tag[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [color, setColor] = useState(DEFAULT_COLOR);
  const [isSubmitting, setIsSubmitting] = useState(false);
  // Bumped after a successful create so the MarkdownEditor remounts empty.
  const [editorResetKey, setEditorResetKey] = useState(0);

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [editColor, setEditColor] = useState(DEFAULT_COLOR);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push("/signin");
    }
  }, [authLoading, isAuthenticated, router]);

  const loadTags = useCallback(async () => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/tags`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) {
        throw new Error("Failed to load tags.");
      }
      const data: TagCollection = await res.json();
      setTags(data.member ?? data["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load tags.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) {
      loadTags();
    }
  }, [isAuthenticated, loadTags]);

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!title.trim()) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/tags`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          title: title.trim(),
          description: description.trim() || null,
          color,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to create tag.",
        );
      }
      setTitle("");
      setDescription("");
      setColor(DEFAULT_COLOR);
      setEditorResetKey((k) => k + 1);
      await loadTags();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create tag.");
    } finally {
      setIsSubmitting(false);
    }
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

  const handleUpdate = async (event: FormEvent<HTMLFormElement>, tag: Tag) => {
    event.preventDefault();
    if (!editTitle.trim()) return;

    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${tag["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          title: editTitle.trim(),
          description: editDescription.trim() || null,
          color: editColor,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to update tag.",
        );
      }
      setEditingId(null);
      await loadTags();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update tag.");
    }
  };

  const handleDelete = async (tag: Tag) => {
    if (!window.confirm(`Delete tag "${tag.title}"? It will be removed from any tasks using it.`)) {
      return;
    }

    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${tag["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) {
        throw new Error("Failed to delete tag.");
      }
      await loadTags();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete tag.");
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
                <Button type="submit" disabled={isSubmitting || !title.trim()}>
                  {isSubmitting ? "Adding..." : "Add Tag"}
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
