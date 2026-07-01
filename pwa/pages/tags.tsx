import Head from "next/head";
import { useRouter } from "next/router";
import { Pencil, Plus, Tag as TagIcon, Trash2 } from "lucide-react";
import { FormEvent, useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";
import ConfirmDialog from "@/components/common/ConfirmDialog";
import PageHeader from "@/components/common/PageHeader";
import { AVATAR_PALETTE } from "@/lib/avatarPalette";
import { ENTRYPOINT } from "@/config/entrypoint";

interface Tag {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  color: string;
}

const DEFAULT_COLOR = AVATAR_PALETTE[0];

const errorMessage = (err: unknown, fallback: string): string =>
  err instanceof Error ? err.message : fallback;

const Tags = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const spaceIri = activeSpace?.["@id"] ?? null;
  const router = useRouter();
  const queryClient = useQueryClient();

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [color, setColor] = useState(DEFAULT_COLOR);
  // Bumped after a successful create so the MarkdownEditor remounts empty.
  const [editorResetKey, setEditorResetKey] = useState(0);

  // Inline create form (opened from the "Add tag" button into the table).
  const [creating, setCreating] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [editColor, setEditColor] = useState(DEFAULT_COLOR);

  // Most recent mutation failure; query failures fall back to the query's
  // own error in `error` below.
  const [actionError, setActionError] = useState<string | null>(null);

  // Delete confirmation: the tag pending deletion + its task-usage count
  // (null while we're still counting).
  const [deleteTarget, setDeleteTarget] = useState<Tag | null>(null);
  const [usageCount, setUsageCount] = useState<number | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const tagsQuery = useQuery({
    queryKey: ["tags", spaceIri],
    enabled: isAuthenticated && !!spaceIri,
    queryFn: () =>
      apiGetCollection<Tag>(
        `/tags?space=${encodeURIComponent(spaceIri as string)}`,
        { errorMessage: "Failed to load tags." },
      ),
  });
  const tags = tagsQuery.data ?? [];
  const refreshTags = () => queryClient.invalidateQueries({ queryKey: ["tags"] });

  const createMutation = useMutation({
    mutationFn: () =>
      apiSend("POST", "/tags", {
        errorMessage: "Failed to create tag.",
        body: {
          title: title.trim(),
          description: description.trim() || null,
          color,
          space: spaceIri,
        },
      }),
    onSuccess: () => {
      setTitle("");
      setDescription("");
      setColor(DEFAULT_COLOR);
      setEditorResetKey((k) => k + 1);
      setCreating(false);
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

  const startCreate = () => {
    setEditingId(null);
    setTitle("");
    setDescription("");
    setColor(DEFAULT_COLOR);
    setEditorResetKey((k) => k + 1);
    setActionError(null);
    setCreating(true);
  };

  const cancelCreate = () => {
    setCreating(false);
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
    setDeleteTarget(tag);
    setUsageCount(null);
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/tasks?tags=${encodeURIComponent(tag["@id"])}&itemsPerPage=1`,
          { credentials: "include", headers: { Accept: "application/ld+json" } },
        );
        const data = res.ok ? await res.json() : {};
        const total = data.totalItems ?? data["hydra:totalItems"] ?? 0;
        setUsageCount(typeof total === "number" ? total : 0);
      } catch {
        setUsageCount(0);
      }
    })();
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
        <title>Tags - Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <PageHeader
            title="Tags"
            icon={<TagIcon className="h-6 w-6 text-cyan-600 dark:text-cyan-400" />}
            count={tagsQuery.isLoading ? null : tags.length}
            subtitle={
              <>
                Shared across everyone in
                {activeSpace ? ` the “${activeSpace.name}” space` : " this space"}.
              </>
            }
            actions={
              can("tags", "create") ? (
                <Button
                  type="button"
                  size="sm"
                  onClick={startCreate}
                  disabled={creating || !spaceIri}
                >
                  <Plus className="mr-1 h-3.5 w-3.5" /> Add tag
                </Button>
              ) : undefined
            }
          />

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {tagsQuery.isLoading ? (
            <p className="text-muted-foreground">Loading tags...</p>
          ) : tags.length === 0 && !creating ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">
                  No tags yet. Use “Add tag” to create one.
                </p>
              </CardContent>
            </Card>
          ) : (
            <div className="overflow-hidden rounded-lg border" data-testid="tag-list">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="px-4 py-2 text-left font-medium">Tag</th>
                    <th className="px-4 py-2 text-left font-medium">Description</th>
                    <th className="px-4 py-2" />
                  </tr>
                </thead>
                <tbody>
                  {creating && (
                    <tr data-testid="tag-create-row" className="border-b last:border-0">
                      <td colSpan={3} className="px-4 py-3">
                        <form onSubmit={handleCreate} className="space-y-3">
                          <Input
                            type="text"
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            required
                            maxLength={100}
                            aria-label="Title"
                            placeholder="Tag name"
                            autoFocus
                          />
                          <MarkdownEditor
                            key={editorResetKey}
                            ariaLabel="Description"
                            value={description}
                            onChange={setDescription}
                          />
                          <ColorSwatchPicker
                            value={color}
                            onChange={setColor}
                            ariaLabel="Color"
                          />
                          <div className="flex gap-2">
                            <Button
                              type="submit"
                              size="sm"
                              disabled={
                                createMutation.isPending || !title.trim() || !spaceIri
                              }
                            >
                              {createMutation.isPending ? "Adding..." : "Add tag"}
                            </Button>
                            <Button
                              type="button"
                              variant="secondary"
                              size="sm"
                              onClick={cancelCreate}
                            >
                              Cancel
                            </Button>
                          </div>
                        </form>
                      </td>
                    </tr>
                  )}
                  {tags.map((tag) =>
                    editingId === tag["@id"] ? (
                      <tr
                        key={tag["@id"]}
                        data-testid="tag-item"
                        className="border-b last:border-0"
                      >
                        <td colSpan={3} className="px-4 py-3">
                          <form
                            onSubmit={(e) => handleUpdate(e, tag)}
                            className="space-y-3"
                          >
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
                            <ColorSwatchPicker
                              value={editColor}
                              onChange={setEditColor}
                              ariaLabel="Color"
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
                        </td>
                      </tr>
                    ) : (
                      <tr
                        key={tag["@id"]}
                        data-testid="tag-item"
                        className="border-b last:border-0 hover:bg-accent/40"
                      >
                        <td className="px-4 py-2 align-top">
                          <span
                            className="inline-block rounded px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-white"
                            style={{ backgroundColor: tag.color }}
                          >
                            {tag.title}
                          </span>
                        </td>
                        <td className="min-w-0 px-4 py-2 align-top">
                          {tag.description ? (
                            <MarkdownView source={tag.description} />
                          ) : (
                            <span className="text-muted-foreground/50">—</span>
                          )}
                        </td>
                        <td className="px-4 py-2 text-right align-top whitespace-nowrap">
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => startEdit(tag)}
                            aria-label={`Edit "${tag.title}"`}
                          >
                            <Pencil className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => handleDelete(tag)}
                            aria-label={`Delete "${tag.title}"`}
                            className="text-destructive hover:text-destructive"
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </td>
                      </tr>
                    ),
                  )}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(o) => {
          if (!o) setDeleteTarget(null);
        }}
        title={
          deleteTarget ? `Delete tag “${deleteTarget.title}”?` : "Delete tag?"
        }
        description={
          usageCount === null
            ? "Checking where this tag is used…"
            : usageCount === 0
              ? "This tag isn’t used anywhere."
              : `This tag is used on ${usageCount} task${usageCount === 1 ? "" : "s"}. Deleting it removes it from those tasks. This can’t be undone.`
        }
        confirmLabel="Delete tag"
        destructive
        onConfirm={async () => {
          if (!deleteTarget) return;
          await deleteMutation.mutateAsync(deleteTarget);
          setDeleteTarget(null);
        }}
      />
    </>
  );
};

export default Tags;
