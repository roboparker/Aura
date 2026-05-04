import { useEffect, useMemo, useState } from "react";
import { Pencil, Reply, Trash2 } from "lucide-react";
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
  // IRI of the parent comment when this is a threaded reply, null at root.
  parentComment: string | null;
  createdAt: string;
  updatedAt: string | null;
}

/**
 * Mirrors the server's `Comment::MAX_DEPTH`. Replies are blocked once a
 * comment sits at this depth so the API stays in sync with the UI.
 */
const MAX_DEPTH = 3;

/**
 * How many sibling replies render expanded by default. Anything past this
 * collapses behind a "Show N more replies" toggle, matching the spec on
 * #105.
 */
const COLLAPSE_AFTER = 3;

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
  /** `parentIri` is null for top-level posts and a comment IRI for replies. */
  onCreate: (body: string, parentIri: string | null) => Promise<void>;
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

interface CommentNode {
  comment: Comment;
  children: CommentNode[];
}

/**
 * Builds the tree from the flat list. Orphan replies (parent missing — can
 * happen briefly while a Mercure delete event races a list reload) get
 * promoted to roots so they don't disappear from the UI.
 */
const buildThreadTree = (comments: Comment[]): CommentNode[] => {
  const nodes = new Map<string, CommentNode>();
  for (const c of comments) {
    nodes.set(c["@id"], { comment: c, children: [] });
  }
  const roots: CommentNode[] = [];
  nodes.forEach((node) => {
    const parentIri = node.comment.parentComment;
    if (parentIri && nodes.has(parentIri)) {
      nodes.get(parentIri)!.children.push(node);
    } else {
      roots.push(node);
    }
  });
  return roots;
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

  const tree = useMemo(() => buildThreadTree(comments), [comments]);

  const submit = async () => {
    const trimmed = draft.trim();
    if (!trimmed) return;
    setSubmitting(true);
    setError(null);
    try {
      await onCreate(draft, null);
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
      ) : tree.length === 0 ? (
        <p className="text-xs text-muted-foreground italic">
          No comments yet — start the discussion.
        </p>
      ) : (
        <ul className="space-y-3">
          {tree.map((node) => (
            <CommentBranch
              key={node.comment["@id"]}
              node={node}
              depth={1}
              currentUserIri={currentUserIri}
              isTaskOwner={isTaskOwner}
              onCreate={onCreate}
              onEdit={onEdit}
              onDelete={onDelete}
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

interface CommentBranchProps {
  node: CommentNode;
  /** 1 for root comments, 2/3 for nested replies. Capped at MAX_DEPTH. */
  depth: number;
  currentUserIri: string | null;
  isTaskOwner: boolean;
  onCreate: (body: string, parentIri: string | null) => Promise<void>;
  onEdit: (comment: Comment, body: string) => Promise<void>;
  onDelete: (comment: Comment) => Promise<void>;
}

// Renders one comment plus its (recursively rendered) children. Indent
// stops growing past MAX_DEPTH so the panel doesn't drift off the right
// edge on long threads.
const CommentBranch = ({
  node,
  depth,
  currentUserIri,
  isTaskOwner,
  onCreate,
  onEdit,
  onDelete,
}: CommentBranchProps) => {
  const { comment, children } = node;
  const [showAllReplies, setShowAllReplies] = useState(false);
  const visibleChildren = showAllReplies
    ? children
    : children.slice(0, COLLAPSE_AFTER);
  const hiddenCount = children.length - visibleChildren.length;
  // Visual indent caps at MAX_DEPTH — anything deeper renders flush with
  // its grandparent. The server-side validator stops new replies from
  // ever creating one of those rows in the first place.
  const childDepth = Math.min(depth + 1, MAX_DEPTH);

  return (
    <li data-testid="comment-item">
      <CommentRow
        comment={comment}
        depth={depth}
        canEdit={
          currentUserIri !== null && currentUserIri === comment.author["@id"]
        }
        canDelete={
          currentUserIri !== null &&
          (currentUserIri === comment.author["@id"] || isTaskOwner)
        }
        canReply={depth < MAX_DEPTH}
        onCreate={onCreate}
        onEdit={(body) => onEdit(comment, body)}
        onDelete={() => onDelete(comment)}
      />
      {children.length > 0 && (
        <ul className="mt-3 space-y-3 border-l border-border pl-4 ml-4">
          {visibleChildren.map((child) => (
            <CommentBranch
              key={child.comment["@id"]}
              node={child}
              depth={childDepth}
              currentUserIri={currentUserIri}
              isTaskOwner={isTaskOwner}
              onCreate={onCreate}
              onEdit={onEdit}
              onDelete={onDelete}
            />
          ))}
          {hiddenCount > 0 && (
            <li>
              <button
                type="button"
                onClick={() => setShowAllReplies(true)}
                className="text-xs text-muted-foreground hover:text-foreground underline-offset-2 hover:underline"
                data-testid="comment-show-more-replies"
              >
                Show {hiddenCount} more {hiddenCount === 1 ? "reply" : "replies"}
              </button>
            </li>
          )}
        </ul>
      )}
    </li>
  );
};

interface CommentRowProps {
  comment: Comment;
  depth: number;
  canEdit: boolean;
  canDelete: boolean;
  canReply: boolean;
  onCreate: (body: string, parentIri: string | null) => Promise<void>;
  onEdit: (body: string) => Promise<void>;
  onDelete: () => Promise<void>;
}

const CommentRow = ({
  comment,
  canEdit,
  canDelete,
  canReply,
  onCreate,
  onEdit,
  onDelete,
}: CommentRowProps) => {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(comment.body);
  const [editorKey, setEditorKey] = useState(0);
  const [savingEdit, setSavingEdit] = useState(false);

  const [replying, setReplying] = useState(false);
  const [replyDraft, setReplyDraft] = useState("");
  const [replyKey, setReplyKey] = useState(0);
  const [postingReply, setPostingReply] = useState(false);
  const [replyError, setReplyError] = useState<string | null>(null);

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

  const startReply = () => {
    setReplyDraft("");
    setReplyKey((k) => k + 1);
    setReplyError(null);
    setReplying(true);
  };

  const cancelReply = () => {
    setReplying(false);
    setReplyDraft("");
    setReplyError(null);
  };

  const submitReply = async () => {
    const trimmed = replyDraft.trim();
    if (!trimmed) return;
    setPostingReply(true);
    setReplyError(null);
    try {
      await onCreate(replyDraft, comment["@id"]);
      setReplying(false);
      setReplyDraft("");
      setReplyKey((k) => k + 1);
    } catch (err) {
      setReplyError(
        err instanceof Error ? err.message : "Failed to post reply.",
      );
    } finally {
      setPostingReply(false);
    }
  };

  return (
    <div className="flex gap-3">
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
            {canReply && !editing && !replying && (
              <button
                type="button"
                onClick={startReply}
                aria-label="Reply to comment"
                className="text-muted-foreground hover:text-foreground p-0.5"
                data-testid="comment-reply"
              >
                <Reply className="h-3.5 w-3.5" />
              </button>
            )}
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
            highlightMentions
          />
        )}
        {replying && (
          <div
            className="space-y-2 mt-2"
            onKeyDown={(e) => {
              if ((e.metaKey || e.ctrlKey) && e.key === "Enter") {
                e.preventDefault();
                void submitReply();
              }
            }}
          >
            {replyError && (
              <Alert variant="destructive" data-testid="comment-reply-error">
                <AlertDescription>{replyError}</AlertDescription>
              </Alert>
            )}
            <MarkdownEditor
              key={replyKey}
              ariaLabel={`Reply to ${displayName(comment.author)}`}
              value={replyDraft}
              onChange={setReplyDraft}
            />
            <div className="flex gap-2">
              <Button
                type="button"
                size="sm"
                onClick={() => void submitReply()}
                disabled={postingReply || replyDraft.trim().length === 0}
                data-testid="comment-reply-submit"
              >
                {postingReply ? "Posting…" : "Reply"}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={cancelReply}
              >
                Cancel
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default CommentsPanel;
