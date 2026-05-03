import { useEffect, useState } from "react";
import { Pencil, Trash2 } from "lucide-react";
import { displayName } from "@/lib/userDisplay";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";

export interface Comment {
  "@id": string;
  id: string;
  body: string;
  // The API serializes Comment.author as the embedded user shape so we can
  // render the avatar without a second fetch per comment.
  author: AvatarUser & { "@id": string; id: string };
  createdAt: string;
  updatedAt: string | null;
}

interface CommentsPanelProps {
  taskTitle: string;
  /** Already-loaded comments for this task. Sorted oldest-first by the API. */
  comments: Comment[];
  /** True while the parent is awaiting the initial comments fetch. */
  isLoading: boolean;
  /** Author check uses this — null means an admin-viewing-someone-else case
   *  where edit/delete should still work via the server's auth rules. */
  currentUserIri: string | null;
  /** Set when the current user is the owner of this task; widens delete
   *  rights for the same reason the server does. */
  isTaskOwner: boolean;
  onCreate: (body: string) => Promise<void>;
  onEdit: (comment: Comment, body: string) => Promise<void>;
  onDelete: (comment: Comment) => Promise<void>;
}

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
  if (abs < 31536000) return RELATIVE.format(Math.round(diffSec / 2592000), "month");
  return RELATIVE.format(Math.round(diffSec / 31536000), "year");
};

const CommentsPanel = ({
  taskTitle,
  comments,
  isLoading,
  currentUserIri,
  isTaskOwner,
  onCreate,
  onEdit,
  onDelete,
}: CommentsPanelProps) => {
  const [draft, setDraft] = useState("");
  const [draftKey, setDraftKey] = useState(0);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    const trimmed = draft.trim();
    if (!trimmed) return;
    setSubmitting(true);
    setError(null);
    try {
      await onCreate(draft);
      setDraft("");
      // Bump the editor key so BlockNote remounts with empty content.
      setDraftKey((k) => k + 1);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to post comment.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="space-y-3" data-testid="comments-panel">
      {error && (
        <Alert variant="destructive" data-testid="comments-error">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {isLoading ? (
        <p className="text-xs text-muted-foreground">Loading comments…</p>
      ) : comments.length === 0 ? (
        <p className="text-xs text-muted-foreground italic">
          No comments yet — start the discussion.
        </p>
      ) : (
        <ul className="space-y-3">
          {comments.map((comment) => (
            <CommentRow
              key={comment["@id"]}
              comment={comment}
              canEdit={
                currentUserIri !== null && currentUserIri === comment.author["@id"]
              }
              canDelete={
                currentUserIri !== null &&
                (currentUserIri === comment.author["@id"] || isTaskOwner)
              }
              onEdit={(body) => onEdit(comment, body)}
              onDelete={() => onDelete(comment)}
            />
          ))}
        </ul>
      )}

      <div
        className="space-y-2"
        // Ctrl/Cmd+Enter submits — keydown bubbles out of BlockNote since
        // it doesn't intercept the modifier+Enter combo for its own use.
        onKeyDown={(e) => {
          if ((e.metaKey || e.ctrlKey) && e.key === "Enter") {
            e.preventDefault();
            void submit();
          }
        }}
      >
        <MarkdownEditor
          key={draftKey}
          ariaLabel={`Comment on "${taskTitle}"`}
          value={draft}
          onChange={setDraft}
        />
        <div className="flex items-center justify-between gap-2">
          <p className="text-xs text-muted-foreground">
            Markdown supported. <kbd>⌘</kbd>/<kbd>Ctrl</kbd>+<kbd>Enter</kbd>{" "}
            to post.
          </p>
          <Button
            type="button"
            size="sm"
            onClick={() => void submit()}
            disabled={submitting || draft.trim().length === 0}
            data-testid="comment-submit"
          >
            {submitting ? "Posting…" : "Post comment"}
          </Button>
        </div>
      </div>
    </div>
  );
};

interface CommentRowProps {
  comment: Comment;
  canEdit: boolean;
  canDelete: boolean;
  onEdit: (body: string) => Promise<void>;
  onDelete: () => Promise<void>;
}

const CommentRow = ({
  comment,
  canEdit,
  canDelete,
  onEdit,
  onDelete,
}: CommentRowProps) => {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(comment.body);
  const [editorKey, setEditorKey] = useState(0);
  const [savingEdit, setSavingEdit] = useState(false);

  useEffect(() => {
    if (!editing) setDraft(comment.body);
  }, [comment.body, editing]);

  const startEdit = () => {
    setDraft(comment.body);
    setEditorKey((k) => k + 1);
    setEditing(true);
  };

  const cancelEdit = () => {
    setDraft(comment.body);
    setEditing(false);
  };

  const saveEdit = async () => {
    const next = draft.trim();
    if (!next) return;
    if (next === comment.body) {
      setEditing(false);
      return;
    }
    setSavingEdit(true);
    try {
      await onEdit(draft);
      setEditing(false);
    } finally {
      setSavingEdit(false);
    }
  };

  const requestDelete = async () => {
    if (!window.confirm("Delete this comment?")) return;
    await onDelete();
  };

  return (
    <li className="flex gap-3" data-testid="comment-item">
      <UserAvatar user={comment.author} size="sm" />
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <span className="font-medium text-foreground">
            {displayName(comment.author)}
          </span>
          <span title={new Date(comment.createdAt).toLocaleString()}>
            {formatRelative(comment.createdAt)}
          </span>
          {comment.updatedAt && (
            <span title={new Date(comment.updatedAt).toLocaleString()}>
              · edited
            </span>
          )}
          <div className="ml-auto flex items-center gap-1">
            {canEdit && !editing && (
              <button
                type="button"
                onClick={startEdit}
                aria-label="Edit comment"
                className="text-muted-foreground hover:text-foreground p-0.5"
                data-testid="comment-edit"
              >
                <Pencil className="h-3.5 w-3.5" />
              </button>
            )}
            {canDelete && !editing && (
              <button
                type="button"
                onClick={requestDelete}
                aria-label="Delete comment"
                className="text-destructive hover:text-destructive/80 p-0.5"
                data-testid="comment-delete"
              >
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            )}
          </div>
        </div>
        {editing ? (
          <div className="space-y-2 mt-1">
            <MarkdownEditor
              key={editorKey}
              ariaLabel="Edit comment"
              value={draft}
              onChange={setDraft}
            />
            <div className="flex gap-2">
              <Button
                type="button"
                size="sm"
                onClick={() => void saveEdit()}
                disabled={savingEdit || draft.trim().length === 0}
                data-testid="comment-edit-save"
              >
                Save
              </Button>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={cancelEdit}
              >
                Cancel
              </Button>
            </div>
          </div>
        ) : (
          <MarkdownView
            source={comment.body}
            className="text-sm mt-1"
          />
        )}
      </div>
    </li>
  );
};

export default CommentsPanel;
