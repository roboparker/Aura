import { useEffect, useState } from "react";
import { GripVertical, MessageSquare, PanelRight, Paperclip, Trash2 } from "lucide-react";
import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import {
  synthesizePastedImageName,
  uploadAttachmentFile,
} from "@/lib/attachments";
import {
  useCommentLiveUpdates,
  type CommentLiveEvent,
} from "@/lib/useCommentLiveUpdates";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import AssigneesCombobox, {
  type AssigneeOption,
} from "@/components/tasks/AssigneesCombobox";
import TagsCombobox from "@/components/tasks/TagsCombobox";
import CommentsPanel, {
  type Comment,
} from "@/components/common/CommentsPanel";
import AttachmentsPanel, {
  type Attachment,
} from "@/components/tasks/AttachmentsPanel";
import DueDateCell from "@/components/tasks/DueDateCell";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { TableCell, TableRow } from "@/components/ui/table";
import {
  dueDateStatus,
  plainTextDescription,
  type RecurrenceRule,
  type Reminder,
  type Tag,
  type Task,
} from "@/components/tasks/taskHelpers";
import { cn } from "@/lib/utils";

interface TaskRowProps {
  task: Task;
  allTags: Tag[];
  assignableUsers: AssigneeOption[];
  reorderable: boolean;
  currentUserIri: string | null;
  comments: Comment[] | undefined;
  commentsLoading: boolean;
  onToggle: (task: Task) => void;
  onDelete: (task: Task) => void;
  onOpenDetail: (task: Task) => void;
  onTagsChange: (task: Task, nextTagIris: string[]) => Promise<void>;
  onTitleChange: (task: Task, nextTitle: string) => Promise<void>;
  onDescriptionChange: (task: Task, nextDescription: string | null) => Promise<void>;
  onDueDateChange: (task: Task, nextDueDate: string | null) => Promise<void>;
  onRecurrenceChange: (task: Task, nextRule: RecurrenceRule | null) => Promise<void>;
  onRemindersChange: (
    task: Task,
    nextReminders: Reminder[] | null,
  ) => Promise<void>;
  onAssigneesChange: (task: Task, nextIris: string[]) => Promise<void>;
  onAssigneeAvatarClick: (assignee: AssigneeOption) => void;
  /** Lazy-load trigger: parent fetches `/comments?task=…` the first time
   *  this fires for a task. */
  onLoadComments: (task: Task) => Promise<void>;
  onCreateComment: (task: Task, body: string) => Promise<void>;
  onEditComment: (comment: Comment, body: string) => Promise<void>;
  onDeleteComment: (comment: Comment) => Promise<void>;
  onAttachMedia: (task: Task, mediaObjectIri: string) => Promise<void>;
  onDetachMedia: (task: Task, attachment: Attachment) => Promise<void>;
  /** Forwarded to useCommentLiveUpdates: parent merges deltas published
   *  by Mercure into commentsByTask state. */
  onCommentLiveEvent: (taskIri: string, event: CommentLiveEvent) => void;
}

const TaskRow = ({
  task,
  allTags,
  assignableUsers,
  reorderable,
  currentUserIri,
  comments,
  commentsLoading,
  onToggle,
  onDelete,
  onOpenDetail,
  onTagsChange,
  onTitleChange,
  onDescriptionChange,
  onDueDateChange,
  onRecurrenceChange,
  onRemindersChange,
  onAssigneesChange,
  onAssigneeAvatarClick,
  onLoadComments,
  onCreateComment,
  onEditComment,
  onDeleteComment,
  onAttachMedia,
  onDetachMedia,
  onCommentLiveEvent,
}: TaskRowProps) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: task["@id"],
    disabled: !reorderable,
  });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  // --- Attachments expansion --------------------------------------------
  // Attachments embed inline on the Task payload, so no lazy fetch — we
  // just toggle the panel visibility. The count is always known.
  const [attachmentsExpanded, setAttachmentsExpanded] = useState(false);
  const [pasteError, setPasteError] = useState<string | null>(null);
  const attachmentCount = task.attachments.length;

  // Paste-to-upload: when the user pastes an image while focused inside this
  // task row (description editor, comment composer, etc.), upload it as an
  // attachment instead of letting the editor try to render the bytes inline.
  // Text and other non-image clipboard payloads pass through untouched.
  const handleTaskPaste = async (e: React.ClipboardEvent<HTMLElement>) => {
    const items = e.clipboardData?.items;
    if (!items) return;
    const imageFiles: File[] = [];
    for (let i = 0; i < items.length; i++) {
      const item = items[i];
      if (item.kind === "file" && item.type.startsWith("image/")) {
        const f = item.getAsFile();
        if (f) imageFiles.push(f);
      }
    }
    if (imageFiles.length === 0) return;
    e.preventDefault();
    setAttachmentsExpanded(true);
    setPasteError(null);
    for (const original of imageFiles) {
      // Browsers usually return clipboard images as `File` with a name like
      // "image.png" and a real type, but native screenshot tools sometimes
      // hand us empty/bogus names. Re-wrap with a synthesised name so the
      // server-side filename slug is meaningful.
      const named =
        original.name && original.name !== "image.png"
          ? original
          : new File([original], synthesizePastedImageName(original.type), {
              type: original.type,
            });
      try {
        const iri = await uploadAttachmentFile(named);
        await onAttachMedia(task, iri);
      } catch (err) {
        setPasteError(err instanceof Error ? err.message : "Paste upload failed.");
      }
    }
  };

  // --- Comments expansion -----------------------------------------------
  // Comments are fetched lazily — the load fires the first time the user
  // expands the section, then the parent caches the result so subsequent
  // toggles are free.
  const [commentsExpanded, setCommentsExpanded] = useState(false);
  const commentCount = comments?.length;
  // Used only to decide whether to render the trash icon on a comment the
  // current user didn't author. The server is the source of truth — a
  // wrong-positive here just yields a 403 on the actual DELETE.
  const isLikelyTaskOwner =
    currentUserIri !== null && task.owner["@id"] === currentUserIri;
  const toggleComments = () => {
    setCommentsExpanded((open) => !open);
  };

  // Trigger the lazy fetch *after* commit so we don't call setState on the
  // parent during a child render (React would warn and bail).
  useEffect(() => {
    if (commentsExpanded && comments === undefined) {
      void onLoadComments(task);
    }
    // `onLoadComments` is memoized in the parent on its dependencies; this
    // effect re-runs only on the values we actually care about.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [commentsExpanded, comments]);

  // Subscribe to live comment updates only while the panel is open.
  // Closes the EventSource on collapse so we don't leave a connection
  // open per task in the list.
  useCommentLiveUpdates(commentsExpanded ? task["@id"] : null, commentsExpanded, (event) => {
    onCommentLiveEvent(task["@id"], event);
  });

  // --- Inline title editing ---------------------------------------------
  const [editingTitle, setEditingTitle] = useState(false);
  const [titleDraft, setTitleDraft] = useState(task.title);
  // Keep the draft in sync if the task's title is updated externally
  // (e.g. an optimistic patch elsewhere or a reload).
  useEffect(() => {
    if (!editingTitle) setTitleDraft(task.title);
  }, [task.title, editingTitle]);

  const startEditTitle = () => {
    setTitleDraft(task.title);
    setEditingTitle(true);
  };
  const cancelTitle = () => {
    setTitleDraft(task.title);
    setEditingTitle(false);
  };
  const saveTitle = async () => {
    const next = titleDraft.trim();
    setEditingTitle(false);
    if (!next || next === task.title) {
      // Empty / unchanged → silently revert; no API call.
      setTitleDraft(task.title);
      return;
    }
    await onTitleChange(task, next);
  };

  // --- Inline description editing ---------------------------------------
  const [editingDesc, setEditingDesc] = useState(false);
  const [descDraft, setDescDraft] = useState(task.description ?? "");
  // BlockNote reads `value` only at mount, so we bump this key each time we
  // open the editor to force a fresh remount with the current saved content.
  const [descEditorKey, setDescEditorKey] = useState(0);

  useEffect(() => {
    if (!editingDesc) setDescDraft(task.description ?? "");
  }, [task.description, editingDesc]);

  const startEditDesc = () => {
    setDescDraft(task.description ?? "");
    setDescEditorKey((k) => k + 1);
    setEditingDesc(true);
  };
  const cancelDesc = () => {
    setDescDraft(task.description ?? "");
    setEditingDesc(false);
  };
  const saveDesc = async () => {
    setEditingDesc(false);
    const trimmed = descDraft.trim();
    const next = trimmed === "" ? null : descDraft;
    const current = task.description ?? null;
    if (next === current) return;
    await onDescriptionChange(task, next);
  };

  const description = plainTextDescription(task.description);
  const titleClass = cn("font-medium", task.completedOn && "line-through text-muted-foreground");

  // Each task gets its own <tbody> so dnd-kit drags the main row + the
  // description sub-row together as a single unit. Multiple <tbody>s in one
  // <table> is valid HTML and avoids fighting the table layout. We also use
  // a `group/task` so hovering either row paints the bg on both, making the
  // pair feel like one card.
  return (
    <tbody
      ref={setNodeRef}
      style={style}
      data-testid="task-item"
      onPaste={handleTaskPaste}
    >
      <TableRow className="border-b-0 hover:bg-transparent">
        <TableCell className="w-8 align-top">
          <button
            type="button"
            aria-label={`Drag to reorder "${task.title}"`}
            className={cn(
              "px-1 text-muted-foreground touch-none bg-transparent border-0",
              reorderable
                ? "cursor-grab active:cursor-grabbing hover:text-foreground"
                : "cursor-not-allowed opacity-30",
            )}
            disabled={!reorderable}
            {...attributes}
            {...listeners}
          >
            <GripVertical className="h-4 w-4" />
          </button>
        </TableCell>
        <TableCell className="w-10 align-top">
          <input
            type="checkbox"
            checked={!!task.completedOn}
            onChange={() => onToggle(task)}
            aria-label={`Mark "${task.title}" as ${task.completedOn ? "incomplete" : "complete"}`}
            className="mt-1 h-4 w-4 shrink-0 cursor-pointer"
          />
        </TableCell>
        <TableCell className="align-top pl-0">
          {editingTitle ? (
            <Input
              autoFocus
              value={titleDraft}
              onChange={(e) => setTitleDraft(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  void saveTitle();
                } else if (e.key === "Escape") {
                  e.preventDefault();
                  cancelTitle();
                }
              }}
              onBlur={() => void saveTitle()}
              maxLength={255}
              aria-label={`Edit title for "${task.title}"`}
              className="h-8"
              data-testid="task-title-input"
            />
          ) : (
            <button
              type="button"
              onClick={startEditTitle}
              aria-label={`Edit title "${task.title}"`}
              className="text-left w-full cursor-text"
              data-testid="task-title"
            >
              <span className={titleClass}>{task.title}</span>
            </button>
          )}
        </TableCell>
        <TableCell className="align-top" data-testid="task-due">
          <DueDateCell
            value={task.dueDate}
            onChange={(next) => onDueDateChange(task, next)}
            ariaLabel={`Due date for "${task.title}"`}
            testIdPrefix="task-due-date"
            recurrenceValue={task.recurrenceRule}
            onRecurrenceChange={(next) => onRecurrenceChange(task, next)}
            remindersValue={task.reminders}
            onRemindersChange={(next) => onRemindersChange(task, next)}
            status={dueDateStatus(task.dueDate, !!task.completedOn)}
          />
        </TableCell>
        <TableCell className="align-top" data-testid="task-tags">
          <TagsCombobox
            value={task.tags}
            options={allTags}
            onChange={(nextIris) => onTagsChange(task, nextIris)}
            subjectLabel={task.title}
          />
        </TableCell>
        <TableCell className="align-top">
          <AssigneesCombobox
            value={task.assignees}
            options={assignableUsers}
            onChange={(nextIris) => onAssigneesChange(task, nextIris)}
            onAvatarClick={onAssigneeAvatarClick}
            subjectLabel={task.title}
          />
        </TableCell>
        <TableCell className="align-top text-right whitespace-nowrap">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => onOpenDetail(task)}
            aria-label={`Open details for "${task.title}"`}
            data-testid="task-open-detail"
          >
            <PanelRight className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            onClick={() => onDelete(task)}
            aria-label={`Delete "${task.title}"`}
            className="text-destructive hover:text-destructive"
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </TableCell>
      </TableRow>
      <TableRow
        className="hover:bg-transparent"
        data-testid="task-description-row"
      >
        {/* Empty cells preserve the column layout so the description cell
            below starts exactly where the title cell does — no fragile
            pixel-math with pl-24. */}
        <TableCell className="w-8" aria-hidden="true" />
        <TableCell className="w-10" aria-hidden="true" />
        <TableCell colSpan={5} className="pl-0 pr-4 pt-0 pb-3 text-sm">
          {editingDesc ? (
            <div className="space-y-2">
              <MarkdownEditor
                key={descEditorKey}
                ariaLabel={`Description for "${task.title}"`}
                value={descDraft}
                onChange={setDescDraft}
              />
              <div className="flex gap-2">
                <Button
                  size="sm"
                  type="button"
                  onClick={() => void saveDesc()}
                  data-testid="task-description-save"
                >
                  Save
                </Button>
                <Button
                  size="sm"
                  type="button"
                  variant="ghost"
                  onClick={cancelDesc}
                >
                  Cancel
                </Button>
              </div>
            </div>
          ) : description ? (
            <button
              type="button"
              onClick={startEditDesc}
              aria-label={`Edit description for "${task.title}"`}
              className="text-left w-full text-muted-foreground whitespace-pre-wrap rounded-sm hover:text-foreground"
              data-testid="task-description"
            >
              {description}
            </button>
          ) : (
            <button
              type="button"
              onClick={startEditDesc}
              aria-label={`Add description for "${task.title}"`}
              className="text-left w-full italic text-muted-foreground/60 hover:text-muted-foreground rounded-sm"
              data-testid="task-description-add"
            >
              Add description
            </button>
          )}
          <div className="mt-2 flex items-center gap-3">
            <button
              type="button"
              onClick={toggleComments}
              aria-expanded={commentsExpanded}
              aria-label={`${commentsExpanded ? "Hide" : "Show"} comments for "${task.title}"`}
              className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground rounded-sm"
              data-testid="task-comments-toggle"
            >
              <MessageSquare className="h-3.5 w-3.5" />
              <span>
                {commentCount === undefined
                  ? "Comments"
                  : commentCount === 0
                    ? "Comment"
                    : `Comments (${commentCount})`}
              </span>
            </button>
            <button
              type="button"
              onClick={() => setAttachmentsExpanded((v) => !v)}
              aria-expanded={attachmentsExpanded}
              aria-label={`${attachmentsExpanded ? "Hide" : "Show"} attachments for "${task.title}"`}
              className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground rounded-sm"
              data-testid="task-attachments-toggle"
            >
              <Paperclip className="h-3.5 w-3.5" />
              <span>
                {attachmentCount === 0
                  ? "Attach"
                  : `Attachments (${attachmentCount})`}
              </span>
            </button>
          </div>
        </TableCell>
      </TableRow>
      {attachmentsExpanded && (
        <TableRow
          className="hover:bg-transparent"
          data-testid="task-attachments-row"
        >
          <TableCell className="w-8" aria-hidden="true" />
          <TableCell className="w-10" aria-hidden="true" />
          <TableCell colSpan={5} className="pl-0 pr-4 pt-0 pb-3">
            {pasteError && (
              <Alert
                variant="destructive"
                className="mb-2"
                data-testid="task-paste-error"
              >
                <AlertDescription>{pasteError}</AlertDescription>
              </Alert>
            )}
            <AttachmentsPanel
              taskTitle={task.title}
              attachments={task.attachments}
              canDeleteAll={isLikelyTaskOwner}
              onAttach={(iri) => onAttachMedia(task, iri)}
              onDetach={(att) => onDetachMedia(task, att)}
            />
          </TableCell>
        </TableRow>
      )}
      {commentsExpanded && (
        <TableRow
          className="hover:bg-transparent"
          data-testid="task-comments-row"
        >
          <TableCell className="w-8" aria-hidden="true" />
          <TableCell className="w-10" aria-hidden="true" />
          <TableCell colSpan={5} className="pl-0 pr-4 pt-0 pb-4">
            <CommentsPanel
              parentLabel={task.title}
              comments={comments ?? []}
              isLoading={commentsLoading && comments === undefined}
              currentUserIri={currentUserIri}
              canModerate={isLikelyTaskOwner}
              onCreate={(body) => onCreateComment(task, body)}
              onEdit={(comment, body) => onEditComment(comment, body)}
              onDelete={(comment) => onDeleteComment(comment)}
            />
          </TableCell>
        </TableRow>
      )}
    </tbody>
  );
};

export default TaskRow;
