import { useCallback, useEffect, useRef, useState } from "react";
import { CheckCircle2, Repeat, Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import ConfirmDialog from "@/components/common/ConfirmDialog";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import AssigneesCombobox, {
  type AssigneeOption,
} from "@/components/tasks/AssigneesCombobox";
import TagsCombobox, { type TagOption } from "@/components/tasks/TagsCombobox";
import AttachmentsPanel from "@/components/tasks/AttachmentsPanel";
import DueDateCell from "@/components/tasks/DueDateCell";
import RecurrenceEditor from "@/components/tasks/RecurrenceEditor";
import RemindersEditor from "@/components/tasks/RemindersEditor";
import TaskRelationshipsPanel from "@/components/tasks/TaskRelationshipsPanel";
import CommentsPanel from "@/components/common/CommentsPanel";
import ActivityPanel from "@/components/activity/ActivityPanel";
import CustomFieldValueList, {
  type CustomFieldValuePair,
} from "@/components/tasks/CustomFieldValueList";
import type { AvatarUser } from "@/components/user/UserAvatar";
import type { CustomFieldDefinition } from "@/components/custom-fields/types";
import {
  dueDateStatus,
  formatRecurrenceSummary,
  type RecurrenceRule,
  type Reminder,
} from "@/components/tasks/taskHelpers";
import { parseViolations, type ViolationMap } from "@/lib/violations";

interface DrawerAttachment {
  "@id": string;
  id: string;
  originalName: string;
  mimeType: string;
  byteSize: number;
  variantUrls: { original?: string };
  downloadUrl?: string | null;
}

interface DrawerComment {
  "@id": string;
  id: string;
  body: string;
  author: AvatarUser & { "@id": string; id: string };
  createdAt: string;
  updatedAt: string | null;
}

interface DrawerTask {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  completedOn: string | null;
  dueDate: string | null;
  owner: { "@id": string };
  recurrenceRule: RecurrenceRule | null;
  reminders: Reminder[] | null;
  attachments: DrawerAttachment[];
  tags: TagOption[];
  assignees: AssigneeOption[];
  board: string | null;
  customFieldValues: CustomFieldValuePair[];
}

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

const membersOf = <T,>(data: Collection<T>): T[] =>
  data.member ?? data["hydra:member"] ?? [];

export interface TaskDetailDrawerProps {
  taskId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  currentUserIri: string | null;
  assignableUsers: AssigneeOption[];
  allTags: TagOption[];
  /** Bubble the updated task up so the list row can re-render in place. */
  onTaskChanged?: (task: DrawerTask) => void;
  /** Called with the deleted task's IRI so the list can drop the row. */
  onTaskDeleted?: (taskIri: string) => void;
}

/**
 * Deep-linkable task detail drawer (Details / Activity / Comments). Fetches
 * the task by id, renders the pinned summary + custom-field value editors,
 * and mounts the existing Comments / Activity / Attachments panels.
 * Non-modal so the in-drawer comboboxes/popovers (which portal to <body>)
 * stay clickable — same fix as the custom-field editor sheet.
 */
const TaskDetailDrawer = ({
  taskId,
  open,
  onOpenChange,
  currentUserIri,
  assignableUsers,
  allTags,
  onTaskChanged,
  onTaskDeleted,
}: TaskDetailDrawerProps) => {
  const [task, setTask] = useState<DrawerTask | null>(null);
  const [definitions, setDefinitions] = useState<CustomFieldDefinition[]>([]);
  const [spaceIri, setSpaceIri] = useState<string | null>(null);
  const [comments, setComments] = useState<DrawerComment[]>([]);
  const [commentsLoading, setCommentsLoading] = useState(false);
  const [loading, setLoading] = useState(false);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [recurrenceOpen, setRecurrenceOpen] = useState(false);
  const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false);
  // Local draft for the WYSIWYG description, debounce-saved on edit.
  const [descDraft, setDescDraft] = useState("");
  const descDirtyRef = useRef(false);

  // Load the task whenever the drawer opens on a new id.
  useEffect(() => {
    if (!open || !taskId) return;
    let cancelled = false;
    setLoading(true);
    setNotFound(false);
    setError(null);
    setTask(null);
    setDefinitions([]);
    setSpaceIri(null);
    setComments([]);
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/tasks/${encodeURIComponent(taskId)}`,
          { credentials: "include", headers: { Accept: "application/ld+json" } },
        );
        if (res.status === 404 || res.status === 403) {
          if (!cancelled) setNotFound(true);
          return;
        }
        if (!res.ok) throw new Error("Failed to load task.");
        const data: DrawerTask = await res.json();
        if (!cancelled) setTask(data);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Failed to load task.");
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [open, taskId]);

  const taskIri = task?.["@id"] ?? null;
  const boardIri = task?.board ?? null;

  // Load the board's custom field definitions + parent space.
  useEffect(() => {
    if (!boardIri) {
      setDefinitions([]);
      setSpaceIri(null);
      return;
    }
    let cancelled = false;
    void (async () => {
      try {
        const [defsRes, globalDefsRes, projRes] = await Promise.all([
          fetch(
            `${ENTRYPOINT}/custom_field_definitions?board=${encodeURIComponent(boardIri)}`,
            { credentials: "include", headers: { Accept: "application/ld+json" } },
          ),
          fetch(
            `${ENTRYPOINT}/global_custom_field_definitions?boards=${encodeURIComponent(boardIri)}`,
            { credentials: "include", headers: { Accept: "application/ld+json" } },
          ),
          fetch(`${ENTRYPOINT}${boardIri}`, {
            credentials: "include",
            headers: { Accept: "application/ld+json" },
          }),
        ]);
        if (!cancelled && (defsRes.ok || globalDefsRes.ok)) {
          // Effective field set = the board's space fields ∪ its opted-in
          // global fields (#global-custom-fields).
          const spaceDefs: CustomFieldDefinition[] = defsRes.ok
            ? membersOf(await defsRes.json())
            : [];
          const globalDefs: CustomFieldDefinition[] = globalDefsRes.ok
            ? membersOf(await globalDefsRes.json())
            : [];
          setDefinitions(
            [...spaceDefs, ...globalDefs].sort((a, b) => a.position - b.position),
          );
        }
        if (projRes.ok && !cancelled) {
          const proj: { space?: string | { "@id": string } } = await projRes.json();
          const iri =
            typeof proj.space === "string" ? proj.space : proj.space?.["@id"];
          setSpaceIri(iri ?? null);
        }
      } catch {
        /* non-fatal — fields/refs just won't render */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [boardIri]);

  // Load comments once the task is known.
  useEffect(() => {
    if (!taskIri) return;
    let cancelled = false;
    setCommentsLoading(true);
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/comments?task=${encodeURIComponent(taskIri)}&itemsPerPage=200`,
          { credentials: "include", headers: { Accept: "application/ld+json" } },
        );
        if (res.ok && !cancelled) {
          const data: Collection<DrawerComment> = await res.json();
          setComments(membersOf(data));
        }
      } finally {
        if (!cancelled) setCommentsLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [taskIri]);

  const applyTask = useCallback(
    (updated: DrawerTask) => {
      setTask(updated);
      onTaskChanged?.(updated);
    },
    [onTaskChanged],
  );

  const patchTask = useCallback(
    async (body: Record<string, unknown>): Promise<Response> => {
      if (!task) throw new Error("No task loaded.");
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify(body),
      });
      if (res.ok) applyTask(await res.json());
      return res;
    },
    [task, applyTask],
  );

  // Delete the task. Throws on failure so the ConfirmDialog keeps its modal
  // open and surfaces the error; on success it closes the drawer and tells the
  // list to drop the row.
  const deleteTask = useCallback(async () => {
    if (!task) return;
    const iri = task["@id"];
    const res = await fetch(`${ENTRYPOINT}${iri}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) throw new Error("Failed to delete task.");
    onOpenChange(false);
    onTaskDeleted?.(iri);
  }, [task, onOpenChange, onTaskDeleted]);

  // Re-seed the description draft when a different task loads.
  const descTaskIri = task?.["@id"] ?? null;
  useEffect(() => {
    descDirtyRef.current = false;
    setDescDraft(task?.description ?? "");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [descTaskIri]);

  // Debounce-save the description after edits.
  useEffect(() => {
    if (!descDirtyRef.current) return;
    const handle = setTimeout(() => {
      void patchTask({ description: descDraft.trim() === "" ? null : descDraft });
    }, 800);
    return () => clearTimeout(handle);
  }, [descDraft, patchTask]);

  const saveCustomFields = useCallback(
    async (next: CustomFieldValuePair[]): Promise<ViolationMap> => {
      const res = await patchTask({ customFieldValues: next });
      if (res.ok) return {};
      return parseViolations(res.clone());
    },
    [patchTask],
  );

  const handleCreateComment = useCallback(
    async (body: string) => {
      if (!task) return;
      const res = await fetch(`${ENTRYPOINT}/comments`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({ task: task["@id"], body }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data["hydra:description"] || data.detail || "Failed to post comment.",
        );
      }
      const created: DrawerComment = await res.json();
      setComments((prev) =>
        prev.some((c) => c["@id"] === created["@id"]) ? prev : [...prev, created],
      );
    },
    [task],
  );

  const handleEditComment = useCallback(
    async (comment: DrawerComment, body: string) => {
      const res = await fetch(`${ENTRYPOINT}${comment["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ body }),
      });
      if (!res.ok) throw new Error("Failed to update comment.");
      const updated: DrawerComment = await res.json();
      setComments((prev) =>
        prev.map((c) => (c["@id"] === comment["@id"] ? { ...c, ...updated } : c)),
      );
    },
    [],
  );

  const handleDeleteComment = useCallback(async (comment: DrawerComment) => {
    const res = await fetch(`${ENTRYPOINT}${comment["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) throw new Error("Failed to delete comment.");
    setComments((prev) => prev.filter((c) => c["@id"] !== comment["@id"]));
  }, []);

  const handleAttach = useCallback(
    async (mediaObjectIri: string) => {
      if (!task) return;
      await patchTask({
        attachments: [...task.attachments.map((a) => a["@id"]), mediaObjectIri],
      });
    },
    [task, patchTask],
  );

  const handleDetach = useCallback(
    async (attachment: DrawerAttachment) => {
      if (!task) return;
      await patchTask({
        attachments: task.attachments
          .filter((a) => a["@id"] !== attachment["@id"])
          .map((a) => a["@id"]),
      });
    },
    [task, patchTask],
  );

  const canModerate =
    currentUserIri !== null && task?.owner["@id"] === currentUserIri;

  return (
    <>
      <Sheet open={open} onOpenChange={onOpenChange} modal={false}>
      <SheetContent
        side="right"
        dim
        className="flex w-full flex-col gap-0 p-0 sm:max-w-xl"
        data-testid="task-detail-drawer"
        onInteractOutside={(e: CustomEvent) => {
          const target = e.detail.originalEvent.target as HTMLElement | null;
          if (
            target?.closest(
              '[data-slot="combobox-content"],[data-slot="popover-content"],[data-radix-popper-content-wrapper]',
            )
          ) {
            e.preventDefault();
          }
        }}
      >
        {loading && (
          <p className="p-6 text-sm text-muted-foreground">Loading…</p>
        )}
        {notFound && (
          <p className="p-6 text-sm text-muted-foreground">
            This task no longer exists, or you don&apos;t have access.
          </p>
        )}
        {error && (
          <Alert variant="destructive" className="m-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {task && (
          <div className="flex min-h-0 flex-1 flex-col">
            <div className="border-b px-5 pt-4 pb-3">
              {/* Top-left mark-done toggle + delete (close button is absolute top-right). */}
              <div className="mb-3 flex items-center gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() =>
                    void patchTask({
                      completedOn: task.completedOn
                        ? null
                        : new Date().toISOString(),
                    })
                  }
                  aria-pressed={Boolean(task.completedOn)}
                  className={
                    task.completedOn
                      ? "border-emerald-600/40 bg-emerald-600/10 text-emerald-700 hover:bg-emerald-600/15 hover:text-emerald-700 dark:text-emerald-400"
                      : ""
                  }
                  data-testid="task-detail-complete"
                >
                  <CheckCircle2 className="mr-1.5 h-4 w-4" />
                  {task.completedOn ? "Completed" : "Mark done"}
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8 text-muted-foreground hover:text-destructive"
                  onClick={() => setConfirmDeleteOpen(true)}
                  aria-label="Delete task"
                  data-testid="task-detail-delete"
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
              <div className="flex items-start gap-2">
                <Input
                  defaultValue={task.title}
                  key={task["@id"] + task.title}
                  onBlur={(e) => {
                    const v = e.target.value.trim();
                    if (v && v !== task.title) void patchTask({ title: v });
                  }}
                  className="h-auto py-2 text-lg font-semibold"
                  data-testid="task-detail-title"
                />
              </div>
            </div>

            <div className="flex min-h-0 flex-1 flex-col space-y-5 overflow-y-auto px-5 py-4">
              <dl className="space-y-3 text-sm">
                <Row label="Tags">
                  <TagsCombobox
                    value={task.tags}
                    options={allTags}
                    onChange={(iris) => void patchTask({ tags: iris })}
                    subjectLabel={task.title}
                  />
                </Row>
                <Row label="Due">
                  <DueDateCell
                    value={task.dueDate}
                    onChange={(next) => void patchTask({ dueDate: next })}
                    ariaLabel={`Due date for ${task.title}`}
                    testIdPrefix="task-detail-due"
                    status={dueDateStatus(task.dueDate, Boolean(task.completedOn))}
                  />
                </Row>
                <Row label="Repeats">
                  <Popover open={recurrenceOpen} onOpenChange={setRecurrenceOpen}>
                    <PopoverTrigger asChild>
                      <button
                        type="button"
                        className="flex items-center gap-1.5 text-left hover:text-foreground"
                        data-testid="task-detail-repeats"
                      >
                        <Repeat className="h-3.5 w-3.5 text-muted-foreground" />
                        {task.recurrenceRule ? (
                          <span>{formatRecurrenceSummary(task.recurrenceRule)}</span>
                        ) : (
                          <span className="text-muted-foreground">Does not repeat</span>
                        )}
                      </button>
                    </PopoverTrigger>
                    <PopoverContent align="start" className="w-auto p-0">
                      <RecurrenceEditor
                        value={task.recurrenceRule}
                        dueDate={task.dueDate}
                        onApply={(rule) => {
                          void patchTask({ recurrenceRule: rule });
                          setRecurrenceOpen(false);
                        }}
                        onRemove={() => {
                          void patchTask({ recurrenceRule: null });
                          setRecurrenceOpen(false);
                        }}
                        onCancel={() => setRecurrenceOpen(false)}
                      />
                    </PopoverContent>
                  </Popover>
                </Row>
                <Row label="Assignees">
                  <AssigneesCombobox
                    value={task.assignees}
                    options={assignableUsers}
                    onChange={(iris) => void patchTask({ assignees: iris })}
                    subjectLabel={task.title}
                    chipsClassName="flex-col items-start gap-2"
                  />
                </Row>
              </dl>

              {task.board && definitions.length > 0 && (
                <CustomFieldValueList
                  definitions={definitions}
                  values={task.customFieldValues}
                  onSave={saveCustomFields}
                  boardIri={task.board}
                  spaceIri={spaceIri}
                  users={assignableUsers}
                />
              )}

              <TaskRelationshipsPanel taskIri={task["@id"]} />

              <div className="space-y-1.5">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  Description
                </p>
                <MarkdownEditor
                  key={`desc-${task["@id"]}`}
                  value={task.description ?? ""}
                  onChange={(v) => {
                    descDirtyRef.current = true;
                    setDescDraft(v);
                  }}
                  ariaLabel="Task description"
                />
              </div>

              <AttachmentsPanel
                taskTitle={task.title}
                attachments={task.attachments}
                canDeleteAll={canModerate}
                onAttach={handleAttach}
                onDetach={handleDetach}
              />

              <RemindersEditor
                value={task.reminders}
                dueDate={task.dueDate}
                onChange={(next) => void patchTask({ reminders: next })}
              />

              <Tabs defaultValue="comments" className="mt-auto border-t pt-4">
                <TabsList variant="line">
                  <TabsTrigger value="comments" data-testid="task-tab-comments">
                    Comments {comments.length > 0 ? `(${comments.length})` : ""}
                  </TabsTrigger>
                  <TabsTrigger value="activity" data-testid="task-tab-activity">
                    Activity
                  </TabsTrigger>
                </TabsList>
                <TabsContent value="comments" className="mt-4">
                  <CommentsPanel
                    parentLabel={task.title}
                    comments={comments}
                    isLoading={commentsLoading}
                    currentUserIri={currentUserIri}
                    canModerate={canModerate}
                    onCreate={handleCreateComment}
                    onEdit={handleEditComment}
                    onDelete={handleDeleteComment}
                  />
                </TabsContent>
                <TabsContent value="activity" className="mt-4">
                  <ActivityPanel endpoint={`/tasks/${task.id}/activity`} />
                </TabsContent>
              </Tabs>
            </div>
          </div>
        )}
      </SheetContent>
      </Sheet>
      {task && (
        <ConfirmDialog
          open={confirmDeleteOpen}
          onOpenChange={setConfirmDeleteOpen}
          title="Delete task?"
          description={`"${task.title}" and its comments will be permanently deleted. This can't be undone.`}
          confirmLabel="Delete"
          onConfirm={deleteTask}
        />
      )}
    </>
  );
};

const Row = ({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) => (
  <div className="flex items-start gap-3">
    <dt className="w-24 shrink-0 pt-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
      {label}
    </dt>
    <dd className="min-w-0 flex-1">{children}</dd>
  </div>
);

export default TaskDetailDrawer;
