import { useCallback, useEffect, useState } from "react";
import { Repeat } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
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
  project: string | null;
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
  const projectIri = task?.project ?? null;

  // Load the project's custom field definitions + parent space.
  useEffect(() => {
    if (!projectIri) {
      setDefinitions([]);
      setSpaceIri(null);
      return;
    }
    let cancelled = false;
    void (async () => {
      try {
        const [defsRes, projRes] = await Promise.all([
          fetch(
            `${ENTRYPOINT}/custom_field_definitions?project=${encodeURIComponent(projectIri)}`,
            { credentials: "include", headers: { Accept: "application/ld+json" } },
          ),
          fetch(`${ENTRYPOINT}${projectIri}`, {
            credentials: "include",
            headers: { Accept: "application/ld+json" },
          }),
        ]);
        if (defsRes.ok && !cancelled) {
          const data: Collection<CustomFieldDefinition> = await defsRes.json();
          setDefinitions(
            membersOf(data).sort((a, b) => a.position - b.position),
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
  }, [projectIri]);

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
    <Sheet open={open} onOpenChange={onOpenChange} modal={false}>
      <SheetContent
        side="right"
        dim
        className="flex w-full flex-col gap-0 p-0 sm:max-w-xl"
        data-testid="task-detail-drawer"
        onInteractOutside={(e) => {
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
          <Tabs defaultValue="details" className="flex min-h-0 flex-1 flex-col">
            <div className="border-b px-5 pt-12 pb-3">
              <div className="flex items-start gap-2">
                <Checkbox
                  checked={Boolean(task.completedOn)}
                  onCheckedChange={(checked) =>
                    void patchTask({
                      completedOn: checked ? new Date().toISOString() : null,
                    })
                  }
                  className="mt-1.5"
                  aria-label="Mark complete"
                  data-testid="task-detail-complete"
                />
                <Input
                  defaultValue={task.title}
                  key={task["@id"] + task.title}
                  onBlur={(e) => {
                    const v = e.target.value.trim();
                    if (v && v !== task.title) void patchTask({ title: v });
                  }}
                  className="border-0 px-0 text-lg font-semibold shadow-none focus-visible:ring-0"
                  data-testid="task-detail-title"
                />
                {task.completedOn && (
                  <span className="mt-1.5 shrink-0 rounded-full bg-primary/15 px-2 py-0.5 text-xs font-medium text-primary">
                    Done
                  </span>
                )}
              </div>
              {task.tags.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1.5 pl-6">
                  {task.tags.map((tag) => (
                    <span
                      key={tag["@id"]}
                      className="rounded px-1.5 py-0.5 text-xs font-medium"
                      style={{
                        backgroundColor: `${tag.color}22`,
                        color: tag.color,
                      }}
                    >
                      {tag.title}
                    </span>
                  ))}
                </div>
              )}
              <TabsList variant="line" className="mt-3">
                <TabsTrigger value="details" data-testid="task-tab-details">
                  Details
                </TabsTrigger>
                <TabsTrigger value="activity" data-testid="task-tab-activity">
                  Activity
                </TabsTrigger>
                <TabsTrigger value="comments" data-testid="task-tab-comments">
                  Comments {comments.length > 0 ? `(${comments.length})` : ""}
                </TabsTrigger>
              </TabsList>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
              <TabsContent value="details" className="space-y-5">
                <dl className="space-y-3 text-sm">
                  <Row label="Assignees">
                    <AssigneesCombobox
                      value={task.assignees}
                      options={assignableUsers}
                      onChange={(iris) => void patchTask({ assignees: iris })}
                      subjectLabel={task.title}
                    />
                  </Row>
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
                </dl>

                <RemindersEditor
                  value={task.reminders}
                  dueDate={task.dueDate}
                  onChange={(next) => void patchTask({ reminders: next })}
                />

                {task.project && definitions.length > 0 && (
                  <CustomFieldValueList
                    definitions={definitions}
                    values={task.customFieldValues}
                    onSave={saveCustomFields}
                    projectIri={task.project}
                    spaceIri={spaceIri}
                    users={assignableUsers}
                  />
                )}

                <AttachmentsPanel
                  taskTitle={task.title}
                  attachments={task.attachments}
                  canDeleteAll={canModerate}
                  onAttach={handleAttach}
                  onDetach={handleDetach}
                />
              </TabsContent>

              <TabsContent value="activity">
                <ActivityPanel endpoint={`/tasks/${task.id}/activity`} />
              </TabsContent>

              <TabsContent value="comments">
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
            </div>
          </Tabs>
        )}
      </SheetContent>
    </Sheet>
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
