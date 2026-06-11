import { ReactNode, useState } from "react";
import { Pencil, Trash2 } from "lucide-react";
import { trackEvent } from "@/lib/analytics";
import { displayName } from "@/lib/userDisplay";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";

/**
 * Flat chronological comment thread (#228). One component for both
 * task and page parents — the surrounding page passes `parentLabel`
 * (used in editor aria labels) and the create/edit/delete handlers
 * it owns; this component knows nothing about the parent type.
 *
 * Reply trees are intentionally gone. Comments display in createdAt
 * order, top-down. `@mention` tokens are rendered inline as plain
 * markdown — recipient notifications fire server-side on persist.
 */
export interface Comment {
  "@id": string;
  id: string;
  body: string;
  /**
   * The API serializes Comment.author as the embedded user shape so
   * the panel renders the avatar without a follow-up fetch.
   */
  author: AvatarUser & { "@id": string; id: string };
  createdAt: string;
  updatedAt: string | null;
}

interface CommentsPanelProps {
  parentLabel: string;
  comments: Comment[];
  isLoading: boolean;
  /** Author check. Null permits the server-side rules to decide. */
  currentUserIri: string | null;
  /** Set when the current user has the parent-specific delete-
   *  escalation right (task owner / page space admin). */
  canModerate: boolean;
  /**
   * Whether the composer is shown. Defaults to true. Discussions pass
   * false on a locked thread so existing comments stay visible while
   * new replies are turned away (mirrored by the server-side lock
   * guard); `composerNotice` renders in the composer's place.
   */
  canCompose?: boolean;
  /** Rendered instead of the composer when `canCompose` is false. */
  composerNotice?: ReactNode;
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
  if (abs < 2592000)
    return RELATIVE.format(Math.round(diffSec / 86400), "day");
  if (abs < 31536000)
    return RELATIVE.format(Math.round(diffSec / 2592000), "month");
  return RELATIVE.format(Math.round(diffSec / 31536000), "year");
};

const CommentsPanel = ({
  parentLabel,
  comments,
  isLoading,
  currentUserIri,
  canModerate,
  canCompose = true,
  composerNotice,
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
      await onCreate(trimmed);
      trackEvent("comment-create");
      setDraft("");
      // Bump the editor key so BlockNote remounts with empty content.
      setDraftKey((k) => k + 1);
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Failed to post comment.",
      );
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
          {comments.map((c) => (
            <CommentItem
              key={c["@id"]}
              comment={c}
              currentUserIri={currentUserIri}
              canModerate={canModerate}
              parentLabel={parentLabel}
              onEdit={onEdit}
              onDelete={onDelete}
            />
          ))}
        </ul>
      )}

      {canCompose ? (
        <div className="space-y-2" data-testid="comments-composer">
          <MarkdownEditor
            key={draftKey}
            value={draft}
            onChange={setDraft}
            ariaLabel={`Comment on "${parentLabel}"`}
          />
          <div className="flex gap-2">
            <Button
              type="button"
              size="sm"
              disabled={submitting || !draft.trim()}
              onClick={() => void submit()}
              data-testid="comment-submit"
            >
              {submitting ? "Posting…" : "Post comment"}
            </Button>
          </div>
        </div>
      ) : (
        composerNotice ?? null
      )}
    </div>
  );
};

interface CommentItemProps {
  comment: Comment;
  currentUserIri: string | null;
  canModerate: boolean;
  parentLabel: string;
  onEdit: (comment: Comment, body: string) => Promise<void>;
  onDelete: (comment: Comment) => Promise<void>;
}

const CommentItem = ({
  comment,
  currentUserIri,
  canModerate,
  parentLabel,
  onEdit,
  onDelete,
}: CommentItemProps) => {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(comment.body);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isAuthor = currentUserIri === comment.author["@id"];
  const canEdit = isAuthor;
  const canDelete = isAuthor || canModerate;

  const saveEdit = async () => {
    const trimmed = draft.trim();
    if (!trimmed) return;
    setBusy(true);
    setError(null);
    try {
      await onEdit(comment, trimmed);
      setEditing(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save edit.");
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!window.confirm("Delete this comment? This can't be undone.")) {
      return;
    }
    setBusy(true);
    try {
      await onDelete(comment);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <li data-testid="comment-item" className="flex gap-3">
      <UserAvatar user={comment.author} size="sm" />
      <div className="min-w-0 flex-1 space-y-1">
        <div className="flex flex-wrap items-baseline gap-2 text-xs text-muted-foreground">
          <span className="font-medium text-foreground" data-testid="comment-author">
            {displayName(comment.author)}
          </span>
          <span title={new Date(comment.createdAt).toLocaleString()}>
            {formatRelative(comment.createdAt)}
          </span>
          {comment.updatedAt && (
            <span className="italic">(edited)</span>
          )}
        </div>
        {editing ? (
          <div className="space-y-2">
            <MarkdownEditor
              value={draft}
              onChange={setDraft}
              ariaLabel={`Edit comment on "${parentLabel}"`}
            />
            {error && (
              <Alert variant="destructive">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}
            <div className="flex gap-2">
              <Button
                type="button"
                size="sm"
                disabled={busy || !draft.trim()}
                onClick={() => void saveEdit()}
                data-testid="comment-edit-save"
              >
                {busy ? "Saving…" : "Save"}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() => {
                  setEditing(false);
                  setDraft(comment.body);
                  setError(null);
                }}
              >
                Cancel
              </Button>
            </div>
          </div>
        ) : (
          <>
            <MarkdownView
              source={comment.body}
              className="text-sm"
              highlightMentions
            />
            {error && (
              <Alert variant="destructive">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}
            {(canEdit || canDelete) && (
              <div className="flex gap-2">
                {canEdit && (
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => setEditing(true)}
                    disabled={busy}
                    data-testid="comment-edit"
                  >
                    <Pencil className="h-3.5 w-3.5 mr-1" /> Edit
                  </Button>
                )}
                {canDelete && (
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => void remove()}
                    disabled={busy}
                    className="text-destructive hover:text-destructive"
                    data-testid="comment-delete"
                  >
                    <Trash2 className="h-3.5 w-3.5 mr-1" /> Delete
                  </Button>
                )}
              </div>
            )}
          </>
        )}
      </div>
    </li>
  );
};

export default CommentsPanel;
