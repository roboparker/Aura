import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Lock, Pencil, Pin, Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { displayName } from "@/lib/userDisplay";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

export type DiscussionCategory =
  | "general"
  | "ideas"
  | "announcements"
  | "q-and-a";

export interface Discussion {
  "@id": string;
  id: string;
  title: string;
  body: string;
  category: DiscussionCategory;
  isPinned: boolean;
  isLocked: boolean;
  createdAt: string;
  updatedAt: string | null;
  author: AvatarUser & { "@id": string; id: string };
}

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

interface Props {
  projectIri: string;
  currentUserIri: string | null;
  isProjectOwner: boolean;
}

const CATEGORIES: { value: DiscussionCategory | "all"; label: string }[] = [
  { value: "all", label: "All" },
  { value: "general", label: "General" },
  { value: "ideas", label: "Ideas" },
  { value: "announcements", label: "Announcements" },
  { value: "q-and-a", label: "Q&A" },
];

const CATEGORY_LABEL: Record<DiscussionCategory, string> = {
  general: "General",
  ideas: "Ideas",
  announcements: "Announcements",
  "q-and-a": "Q&A",
};

const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });

const formatRelative = (iso: string): string => {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diffSec = Math.round((ts - Date.now()) / 1000);
  const abs = Math.abs(diffSec);
  if (abs < 60) return RELATIVE.format(diffSec, "second");
  if (abs < 3600) return RELATIVE.format(Math.round(diffSec / 60), "minute");
  if (abs < 86400) return RELATIVE.format(Math.round(diffSec / 3600), "hour");
  if (abs < 2592000) return RELATIVE.format(Math.round(diffSec / 86400), "day");
  if (abs < 31536000)
    return RELATIVE.format(Math.round(diffSec / 2592000), "month");
  return RELATIVE.format(Math.round(diffSec / 31536000), "year");
};

const errorMessage = async (res: Response): Promise<string> => {
  const data = await res.json().catch(() => ({}));
  return (
    data.detail ||
    data.description ||
    data["hydra:description"] ||
    "Request failed."
  );
};

const DiscussionsPanel = ({
  projectIri,
  currentUserIri,
  isProjectOwner,
}: Props) => {
  const [discussions, setDiscussions] = useState<Discussion[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [filter, setFilter] = useState<DiscussionCategory | "all">("all");

  // Composer
  const [showComposer, setShowComposer] = useState(false);
  const [newTitle, setNewTitle] = useState("");
  const [newBody, setNewBody] = useState("");
  const [newCategory, setNewCategory] = useState<DiscussionCategory>("general");
  const [bodyKey, setBodyKey] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [composerError, setComposerError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      const url = `${ENTRYPOINT}/discussions?project=${encodeURIComponent(projectIri)}`;
      const res = await fetch(url, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) throw new Error("Failed to load discussions.");
      const data: Collection<Discussion> = await res.json();
      setDiscussions(data.member ?? data["hydra:member"] ?? []);
    } catch (err) {
      setLoadError(
        err instanceof Error ? err.message : "Failed to load discussions.",
      );
    } finally {
      setIsLoading(false);
    }
  }, [projectIri]);

  useEffect(() => {
    void load();
  }, [load]);

  const filtered = useMemo(() => {
    if (filter === "all") return discussions;
    return discussions.filter((d) => d.category === filter);
  }, [discussions, filter]);

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const title = newTitle.trim();
    const body = newBody.trim();
    if (!title || !body) return;

    setSubmitting(true);
    setComposerError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/discussions`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          project: projectIri,
          title,
          body,
          category: newCategory,
        }),
      });
      if (!res.ok) {
        throw new Error(await errorMessage(res));
      }
      const created: Discussion = await res.json();
      setDiscussions((prev) => sortDiscussions([created, ...prev]));
      setNewTitle("");
      setNewBody("");
      setNewCategory("general");
      setBodyKey((k) => k + 1);
      setShowComposer(false);
    } catch (err) {
      setComposerError(
        err instanceof Error ? err.message : "Failed to start discussion.",
      );
    } finally {
      setSubmitting(false);
    }
  };

  const patchDiscussion = useCallback(
    async (
      discussion: Discussion,
      patch: Partial<
        Pick<Discussion, "title" | "body" | "category" | "isPinned" | "isLocked">
      >,
    ): Promise<Discussion> => {
      const res = await fetch(`${ENTRYPOINT}${discussion["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify(patch),
      });
      if (!res.ok) {
        throw new Error(await errorMessage(res));
      }
      const updated: Discussion = await res.json();
      setDiscussions((prev) =>
        sortDiscussions(
          prev.map((d) => (d["@id"] === updated["@id"] ? updated : d)),
        ),
      );
      return updated;
    },
    [],
  );

  const deleteDiscussion = useCallback(async (discussion: Discussion) => {
    const res = await fetch(`${ENTRYPOINT}${discussion["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) {
      throw new Error(await errorMessage(res));
    }
    setDiscussions((prev) =>
      prev.filter((d) => d["@id"] !== discussion["@id"]),
    );
  }, []);

  return (
    <div className="space-y-4" data-testid="discussions-panel">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div
          className="flex flex-wrap gap-1"
          role="tablist"
          aria-label="Filter by category"
        >
          {CATEGORIES.map((c) => (
            <button
              key={c.value}
              type="button"
              role="tab"
              aria-selected={filter === c.value}
              onClick={() => setFilter(c.value)}
              className={cn(
                "px-3 py-1 rounded-full text-xs font-medium border transition-colors",
                filter === c.value
                  ? "bg-primary text-primary-foreground border-primary"
                  : "bg-background text-muted-foreground border-input hover:text-foreground",
              )}
            >
              {c.label}
            </button>
          ))}
        </div>
        <Button
          type="button"
          size="sm"
          onClick={() => setShowComposer((v) => !v)}
          data-testid="discussion-toggle-composer"
        >
          {showComposer ? "Cancel" : "Start a discussion"}
        </Button>
      </div>

      {showComposer && (
        <Card data-testid="discussion-composer">
          <CardContent className="pt-6">
            <form onSubmit={handleCreate} className="space-y-3">
              <div className="space-y-1.5">
                <Label htmlFor="discussion-title">Title</Label>
                <Input
                  id="discussion-title"
                  type="text"
                  value={newTitle}
                  onChange={(e) => setNewTitle(e.target.value)}
                  required
                  maxLength={200}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="discussion-category">Category</Label>
                <select
                  id="discussion-category"
                  value={newCategory}
                  onChange={(e) =>
                    setNewCategory(e.target.value as DiscussionCategory)
                  }
                  className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  {(Object.keys(CATEGORY_LABEL) as DiscussionCategory[]).map(
                    (cat) => (
                      <option key={cat} value={cat}>
                        {CATEGORY_LABEL[cat]}
                      </option>
                    ),
                  )}
                </select>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="discussion-body">Body</Label>
                <MarkdownEditor
                  key={bodyKey}
                  id="discussion-body"
                  ariaLabel="Discussion body"
                  value={newBody}
                  onChange={setNewBody}
                />
              </div>
              {composerError && (
                <Alert variant="destructive">
                  <AlertDescription>{composerError}</AlertDescription>
                </Alert>
              )}
              <Button
                type="submit"
                disabled={
                  submitting || !newTitle.trim() || !newBody.trim()
                }
                data-testid="discussion-submit"
              >
                {submitting ? "Posting…" : "Post"}
              </Button>
            </form>
          </CardContent>
        </Card>
      )}

      {loadError && (
        <Alert variant="destructive">
          <AlertDescription>{loadError}</AlertDescription>
        </Alert>
      )}

      {isLoading ? (
        <p className="text-muted-foreground text-sm">Loading discussions…</p>
      ) : filtered.length === 0 ? (
        <Card>
          <CardContent className="pt-6">
            <p className="text-muted-foreground text-sm">
              {filter === "all"
                ? "No discussions yet — start the first one."
                : "No discussions in this category yet."}
            </p>
          </CardContent>
        </Card>
      ) : (
        <ul className="space-y-3" data-testid="discussion-list">
          {filtered.map((d) => (
            <DiscussionItem
              key={d["@id"]}
              discussion={d}
              currentUserIri={currentUserIri}
              isProjectOwner={isProjectOwner}
              onPatch={patchDiscussion}
              onDelete={deleteDiscussion}
            />
          ))}
        </ul>
      )}
    </div>
  );
};

const sortDiscussions = (list: Discussion[]): Discussion[] =>
  [...list].sort((a, b) => {
    if (a.isPinned !== b.isPinned) return a.isPinned ? -1 : 1;
    return (
      new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime()
    );
  });

interface ItemProps {
  discussion: Discussion;
  currentUserIri: string | null;
  isProjectOwner: boolean;
  onPatch: (
    d: Discussion,
    patch: Partial<
      Pick<Discussion, "title" | "body" | "category" | "isPinned" | "isLocked">
    >,
  ) => Promise<Discussion>;
  onDelete: (d: Discussion) => Promise<void>;
}

const DiscussionItem = ({
  discussion,
  currentUserIri,
  isProjectOwner,
  onPatch,
  onDelete,
}: ItemProps) => {
  const [expanded, setExpanded] = useState(false);
  const [editing, setEditing] = useState(false);
  const [editTitle, setEditTitle] = useState(discussion.title);
  const [editBody, setEditBody] = useState(discussion.body);
  const [editCategory, setEditCategory] = useState<DiscussionCategory>(
    discussion.category,
  );
  const [editorKey, setEditorKey] = useState(0);
  const [savingEdit, setSavingEdit] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isAuthor = currentUserIri === discussion.author["@id"];
  const canEdit = isAuthor;
  const canDelete = isAuthor || isProjectOwner;
  const canModerate = isProjectOwner;

  const startEdit = () => {
    setEditTitle(discussion.title);
    setEditBody(discussion.body);
    setEditCategory(discussion.category);
    setEditorKey((k) => k + 1);
    setEditing(true);
    setExpanded(true);
  };

  const cancelEdit = () => {
    setEditing(false);
    setError(null);
  };

  const saveEdit = async () => {
    if (!editTitle.trim() || !editBody.trim()) return;
    setSavingEdit(true);
    setError(null);
    try {
      await onPatch(discussion, {
        title: editTitle.trim(),
        body: editBody,
        category: editCategory,
      });
      setEditing(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save.");
    } finally {
      setSavingEdit(false);
    }
  };

  const togglePin = async () => {
    setBusy(true);
    setError(null);
    try {
      await onPatch(discussion, { isPinned: !discussion.isPinned });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update.");
    } finally {
      setBusy(false);
    }
  };

  const toggleLock = async () => {
    setBusy(true);
    setError(null);
    try {
      await onPatch(discussion, { isLocked: !discussion.isLocked });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update.");
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!window.confirm(`Delete discussion "${discussion.title}"?`)) return;
    setBusy(true);
    setError(null);
    try {
      await onDelete(discussion);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete.");
      setBusy(false);
    }
  };

  return (
    <li data-testid="discussion-item">
      <Card>
        <CardContent className="pt-4 pb-4">
          <div className="flex items-start gap-3">
            <UserAvatar user={discussion.author} size="sm" />
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  onClick={() => setExpanded((v) => !v)}
                  className="font-semibold text-left hover:underline"
                  data-testid="discussion-title"
                >
                  {discussion.title}
                </button>
                {discussion.isPinned && (
                  <Badge variant="secondary" className="gap-1">
                    <Pin className="h-3 w-3" /> Pinned
                  </Badge>
                )}
                {discussion.isLocked && (
                  <Badge variant="secondary" className="gap-1">
                    <Lock className="h-3 w-3" /> Locked
                  </Badge>
                )}
                <Badge variant="outline">
                  {CATEGORY_LABEL[discussion.category]}
                </Badge>
              </div>
              <p className="text-xs text-muted-foreground mt-0.5">
                {displayName(discussion.author)} ·{" "}
                {formatRelative(discussion.createdAt)}
                {discussion.updatedAt && " · edited"}
              </p>

              {expanded && !editing && (
                <div className="mt-3">
                  <MarkdownView source={discussion.body} className="text-sm" />
                </div>
              )}

              {editing && (
                <div className="mt-3 space-y-3">
                  <div className="space-y-1.5">
                    <Label htmlFor={`edit-title-${discussion.id}`}>Title</Label>
                    <Input
                      id={`edit-title-${discussion.id}`}
                      value={editTitle}
                      onChange={(e) => setEditTitle(e.target.value)}
                      maxLength={200}
                      required
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor={`edit-cat-${discussion.id}`}>
                      Category
                    </Label>
                    <select
                      id={`edit-cat-${discussion.id}`}
                      value={editCategory}
                      onChange={(e) =>
                        setEditCategory(e.target.value as DiscussionCategory)
                      }
                      className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    >
                      {(
                        Object.keys(CATEGORY_LABEL) as DiscussionCategory[]
                      ).map((cat) => (
                        <option key={cat} value={cat}>
                          {CATEGORY_LABEL[cat]}
                        </option>
                      ))}
                    </select>
                  </div>
                  <MarkdownEditor
                    key={editorKey}
                    ariaLabel="Edit discussion body"
                    value={editBody}
                    onChange={setEditBody}
                  />
                  <div className="flex gap-2">
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => void saveEdit()}
                      disabled={
                        savingEdit ||
                        !editTitle.trim() ||
                        !editBody.trim()
                      }
                      data-testid="discussion-save-edit"
                    >
                      {savingEdit ? "Saving…" : "Save"}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={cancelEdit}
                      disabled={savingEdit}
                    >
                      Cancel
                    </Button>
                  </div>
                </div>
              )}

              {error && (
                <p
                  role="alert"
                  className="mt-2 text-sm text-destructive"
                  data-testid="discussion-error"
                >
                  {error}
                </p>
              )}

              {!editing && (canEdit || canDelete || canModerate) && (
                <div className="mt-2 flex flex-wrap gap-2">
                  {canEdit && (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      onClick={startEdit}
                      disabled={busy}
                      data-testid="discussion-edit"
                    >
                      <Pencil className="h-3.5 w-3.5 mr-1" /> Edit
                    </Button>
                  )}
                  {canModerate && (
                    <>
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => void togglePin()}
                        disabled={busy}
                        data-testid="discussion-toggle-pin"
                      >
                        <Pin className="h-3.5 w-3.5 mr-1" />
                        {discussion.isPinned ? "Unpin" : "Pin"}
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => void toggleLock()}
                        disabled={busy}
                        data-testid="discussion-toggle-lock"
                      >
                        <Lock className="h-3.5 w-3.5 mr-1" />
                        {discussion.isLocked ? "Unlock" : "Lock"}
                      </Button>
                    </>
                  )}
                  {canDelete && (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      onClick={() => void remove()}
                      disabled={busy}
                      className="text-destructive hover:text-destructive"
                      data-testid="discussion-delete"
                    >
                      <Trash2 className="h-3.5 w-3.5 mr-1" /> Delete
                    </Button>
                  )}
                </div>
              )}
            </div>
          </div>
        </CardContent>
      </Card>
    </li>
  );
};

export default DiscussionsPanel;
