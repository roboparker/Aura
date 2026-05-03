import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ArrowDown, ArrowUp, ArrowUpDown, GripVertical, Trash2 } from "lucide-react";
import {
  DndContext,
  DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
} from "@dnd-kit/core";
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import TagsCombobox from "@/components/tasks/TagsCombobox";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Table,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { cn } from "@/lib/utils";

interface Tag {
  "@id": string;
  id: string;
  title: string;
  color: string;
}

interface Task {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  completedOn: string | null;
  dueDate: string | null;
  position: number;
  tags: Tag[];
}

interface Collection<T> {
  // API Platform 4 emits JSON-LD 1.1 (`member`); older versions use `hydra:member`.
  member?: T[];
  "hydra:member"?: T[];
}

// "manual" maps to the persisted `position` order — drag-to-reorder is only
// active in this mode because anything else would snap rows back the moment
// we re-sorted. The other keys are derived sort orders that don't touch the
// underlying tasks array, just the rendered view.
type SortKey = "manual" | "completed" | "title" | "due";
type SortDir = "asc" | "desc";

interface SortState {
  key: SortKey;
  dir: SortDir;
}

const DEFAULT_SORT: SortState = { key: "manual", dir: "asc" };

// Native `<input type="date">` works in YYYY-MM-DD; the API stores a full
// ISO datetime. We persist UTC midnight on the picked day so round-trips
// are stable across timezones — what the user picked is what they see.
const isoToDateInput = (iso: string | null): string => {
  if (!iso) return "";
  // Slice off the date portion of the ISO string so we read back exactly
  // the day the user picked, regardless of local timezone offsets.
  return iso.slice(0, 10);
};

const dateInputToIso = (value: string): string | null => {
  if (!value) return null;
  return `${value}T00:00:00+00:00`;
};

const dueDateFormatter = new Intl.DateTimeFormat(undefined, {
  year: "numeric",
  month: "short",
  day: "numeric",
});

const formatDueDate = (iso: string | null): string => {
  if (!iso) return "";
  // Build the date from the YYYY-MM-DD slice so we ignore the stored UTC
  // time portion — picking "Jun 1" should display "Jun 1" everywhere.
  const [year, month, day] = iso.slice(0, 10).split("-").map(Number);
  if (!year || !month || !day) return "";
  return dueDateFormatter.format(new Date(year, month - 1, day));
};

interface DueDateCellProps {
  value: string | null;
  onChange: (next: string | null) => void | Promise<void>;
  ariaLabel: string;
  testIdPrefix: string;
}

const DueDateCell = ({ value, onChange, ariaLabel, testIdPrefix }: DueDateCellProps) => {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(isoToDateInput(value));

  useEffect(() => {
    if (!editing) setDraft(isoToDateInput(value));
  }, [value, editing]);

  const commit = (raw: string) => {
    setEditing(false);
    const next = dateInputToIso(raw);
    if (next === value) return;
    void onChange(next);
  };

  if (editing) {
    return (
      <input
        autoFocus
        type="date"
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={(e) => commit(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter") {
            e.preventDefault();
            commit((e.target as HTMLInputElement).value);
          } else if (e.key === "Escape") {
            e.preventDefault();
            setDraft(isoToDateInput(value));
            setEditing(false);
          }
        }}
        aria-label={ariaLabel}
        data-testid={`${testIdPrefix}-input`}
        className="h-8 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs"
      />
    );
  }

  if (value) {
    return (
      <button
        type="button"
        onClick={() => setEditing(true)}
        aria-label={ariaLabel}
        className="text-left text-sm rounded-sm hover:text-foreground"
        data-testid={testIdPrefix}
      >
        {formatDueDate(value)}
      </button>
    );
  }

  return (
    <button
      type="button"
      onClick={() => setEditing(true)}
      aria-label={ariaLabel}
      className="text-left text-sm italic text-muted-foreground/60 hover:text-muted-foreground rounded-sm"
      data-testid={`${testIdPrefix}-add`}
    >
      Add date
    </button>
  );
};

// Strip the most common markdown punctuation so the description in the
// dedicated sub-row reads as plain text. We keep paragraph breaks via `\n`
// so multi-paragraph descriptions still feel structured. A real markdown
// renderer (`MarkdownView`) can replace this later if we want bold/links.
const plainTextDescription = (markdown: string | null): string => {
  if (!markdown) return "";
  return markdown
    .replace(/`{3}[\s\S]*?`{3}/g, "")
    .replace(/`([^`]+)`/g, "$1")
    .replace(/^[#>*_~`\-]+\s*/gm, "")
    .replace(/\!?\[([^\]]*)\]\([^)]*\)/g, "$1")
    .replace(/[*_~]+/g, "")
    .replace(/[ \t]+/g, " ")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
};

interface SortableHeaderProps {
  label: string;
  sortKey: SortKey;
  active: SortState;
  onSort: (key: SortKey) => void;
  className?: string;
}

const SortableHeader = ({ label, sortKey, active, onSort, className }: SortableHeaderProps) => {
  const isActive = active.key === sortKey;
  const Icon = isActive ? (active.dir === "asc" ? ArrowUp : ArrowDown) : ArrowUpDown;
  return (
    <TableHead className={className}>
      <button
        type="button"
        onClick={() => onSort(sortKey)}
        className="inline-flex items-center gap-1 -ml-2 px-2 py-1 rounded-sm hover:bg-accent text-left font-medium"
        aria-sort={
          isActive ? (active.dir === "asc" ? "ascending" : "descending") : "none"
        }
      >
        <span>{label}</span>
        <Icon className={cn("h-3.5 w-3.5", isActive ? "opacity-100" : "opacity-50")} />
      </button>
    </TableHead>
  );
};

interface TaskRowProps {
  task: Task;
  allTags: Tag[];
  reorderable: boolean;
  onToggle: (task: Task) => void;
  onDelete: (task: Task) => void;
  onTagsChange: (task: Task, nextTagIris: string[]) => Promise<void>;
  onTitleChange: (task: Task, nextTitle: string) => Promise<void>;
  onDescriptionChange: (task: Task, nextDescription: string | null) => Promise<void>;
  onDueDateChange: (task: Task, nextDueDate: string | null) => Promise<void>;
}

const TaskRow = ({
  task,
  allTags,
  reorderable,
  onToggle,
  onDelete,
  onTagsChange,
  onTitleChange,
  onDescriptionChange,
  onDueDateChange,
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
    <tbody ref={setNodeRef} style={style} data-testid="task-item">
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
        <TableCell className="align-top text-right">
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
        <TableCell colSpan={4} className="pl-0 pr-4 pt-0 pb-3 text-sm">
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
        </TableCell>
      </TableRow>
    </tbody>
  );
};

interface NewTaskInput {
  title: string;
  description: string | null;
  tags: string[];
  dueDate: string | null;
}

interface NewTaskRowProps {
  allTags: Tag[];
  /** Resolves on success, rejects on failure so we know whether to clear the draft. */
  onCreate: (input: NewTaskInput) => Promise<void>;
  isCreating: boolean;
}

// Inline "add a task" row that lives at the top of the table. Mirrors the
// layout of TaskRow (main row + description sub-row) so the user can stage
// title, tags, and description before pressing Enter to submit. Failures
// keep the draft intact (parent shows the error).
const NewTaskRow = ({ allTags, onCreate, isCreating }: NewTaskRowProps) => {
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState<string | null>(null);
  const [tags, setTags] = useState<Tag[]>([]);
  const [dueDate, setDueDate] = useState<string | null>(null);
  const titleInputRef = useRef<HTMLInputElement | null>(null);

  // Description inline editing — local-only; nothing hits the API until
  // submit. Mirrors TaskRow's editing state machine.
  const [editingDesc, setEditingDesc] = useState(false);
  const [descDraft, setDescDraft] = useState("");
  const [descEditorKey, setDescEditorKey] = useState(0);

  const startEditDesc = () => {
    setDescDraft(description ?? "");
    setDescEditorKey((k) => k + 1);
    setEditingDesc(true);
  };
  const cancelDesc = () => {
    setDescDraft(description ?? "");
    setEditingDesc(false);
  };
  const saveDesc = () => {
    setEditingDesc(false);
    const trimmed = descDraft.trim();
    setDescription(trimmed === "" ? null : descDraft);
  };

  const handleTagsChange = (nextIris: string[]) => {
    const next = nextIris
      .map((iri) => allTags.find((tag) => tag["@id"] === iri))
      .filter((tag): tag is Tag => Boolean(tag));
    setTags(next);
  };

  const reset = () => {
    setTitle("");
    setDescription(null);
    setTags([]);
    setDueDate(null);
    setEditingDesc(false);
    setDescDraft("");
  };

  const submit = async () => {
    const trimmed = title.trim();
    if (!trimmed) return;
    try {
      await onCreate({
        title: trimmed,
        description,
        tags: tags.map((tag) => tag["@id"]),
        dueDate,
      });
      reset();
      // Refocus on next tick so the input isn't briefly disabled when we
      // try to focus it.
      requestAnimationFrame(() => titleInputRef.current?.focus());
    } catch {
      // Parent has already surfaced the error in the alert region; keep
      // the draft so the user can retry without retyping.
    }
  };

  const descriptionPreview = plainTextDescription(description);

  return (
    <tbody data-testid="new-task-row">
      <TableRow className="border-b-0 hover:bg-transparent">
        <TableCell className="w-8" aria-hidden="true" />
        <TableCell className="w-10" aria-hidden="true" />
        <TableCell className="pl-0 align-top">
          <Input
            ref={titleInputRef}
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                void submit();
              } else if (e.key === "Escape") {
                e.preventDefault();
                reset();
                titleInputRef.current?.blur();
              }
            }}
            placeholder="Add a task…"
            maxLength={255}
            disabled={isCreating}
            aria-label="New task title"
            className="h-8"
            data-testid="new-task-title-input"
          />
        </TableCell>
        <TableCell className="align-top" data-testid="new-task-due">
          <DueDateCell
            value={dueDate}
            onChange={(next) => setDueDate(next)}
            ariaLabel="Due date for new task"
            testIdPrefix="new-task-due-date"
          />
        </TableCell>
        <TableCell className="align-top" data-testid="new-task-tags">
          <TagsCombobox
            value={tags}
            options={allTags}
            onChange={handleTagsChange}
            subjectLabel="new task"
          />
        </TableCell>
        <TableCell aria-hidden="true" />
      </TableRow>
      <TableRow
        className="hover:bg-transparent"
        data-testid="new-task-description-row"
      >
        <TableCell className="w-8" aria-hidden="true" />
        <TableCell className="w-10" aria-hidden="true" />
        <TableCell colSpan={4} className="pl-0 pr-4 pt-0 pb-3 text-sm">
          {editingDesc ? (
            <div className="space-y-2">
              <MarkdownEditor
                key={descEditorKey}
                ariaLabel="Description for new task"
                value={descDraft}
                onChange={setDescDraft}
              />
              <div className="flex gap-2">
                <Button
                  size="sm"
                  type="button"
                  onClick={saveDesc}
                  data-testid="new-task-description-save"
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
          ) : descriptionPreview ? (
            <button
              type="button"
              onClick={startEditDesc}
              aria-label="Edit description for new task"
              className="text-left w-full text-muted-foreground whitespace-pre-wrap rounded-sm hover:text-foreground"
              data-testid="new-task-description"
            >
              {descriptionPreview}
            </button>
          ) : (
            <button
              type="button"
              onClick={startEditDesc}
              aria-label="Add description for new task"
              className="text-left w-full italic text-muted-foreground/60 hover:text-muted-foreground rounded-sm"
              data-testid="new-task-description-add"
            >
              Add description
            </button>
          )}
        </TableCell>
      </TableRow>
    </tbody>
  );
};

const Tasks = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [tasks, setTasks] = useState<Task[]>([]);
  const [allTags, setAllTags] = useState<Tag[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [sort, setSort] = useState<SortState>(DEFAULT_SORT);

  const sensors = useSensors(
    // Require an 8px drag before activating so a quick click on the grip
    // doesn't get misinterpreted as a reorder attempt.
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const loadData = useCallback(async () => {
    setError(null);
    try {
      // Load tasks and tags in parallel — the task list embeds its current
      // tag badges, the full tag list populates the "+ Tag" picker.
      const [tasksRes, tagsRes] = await Promise.all([
        fetch(`${ENTRYPOINT}/tasks`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
        fetch(`${ENTRYPOINT}/tags`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
      ]);
      if (!tasksRes.ok) {
        throw new Error("Failed to load tasks.");
      }
      if (!tagsRes.ok) {
        throw new Error("Failed to load tags.");
      }
      const tasksData: Collection<Task> = await tasksRes.json();
      const tagsData: Collection<Tag> = await tagsRes.json();
      setTasks(tasksData.member ?? tasksData["hydra:member"] ?? []);
      setAllTags(tagsData.member ?? tagsData["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load tasks.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) {
      loadData();
    }
  }, [isAuthenticated, loadData]);

  const handleCreate = async (input: NewTaskInput) => {
    const trimmed = input.title.trim();
    if (!trimmed) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/tasks`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          title: trimmed,
          description: input.description,
          tags: input.tags,
          dueDate: input.dueDate,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to create task.",
        );
      }
      await loadData();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create task.");
      // Re-throw so NewTaskRow keeps the user's draft for retry.
      throw err;
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleToggle = async (task: Task) => {
    // Optimistic toggle: flip the checkbox immediately so the UI feels
    // responsive and so controlled-input assertions in tests see the new
    // state without waiting for the server round-trip.
    const previous = tasks;
    const nextCompletedOn = task.completedOn ? null : new Date().toISOString();
    setTasks(
      tasks.map((t) => (t["@id"] === task["@id"] ? { ...t, completedOn: nextCompletedOn } : t)),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ completedOn: nextCompletedOn }),
      });
      if (!res.ok) {
        throw new Error("Failed to update task.");
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update task.");
    }
  };

  const handleDelete = async (task: Task) => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) {
        throw new Error("Failed to delete task.");
      }
      await loadData();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete task.");
    }
  };

  const handleTitleChange = async (task: Task, nextTitle: string) => {
    // Optimistic title update — input has already returned to read mode.
    const previous = tasks;
    setTasks(tasks.map((t) => (t["@id"] === task["@id"] ? { ...t, title: nextTitle } : t)));
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ title: nextTitle }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to update title.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update title.");
    }
  };

  const handleDescriptionChange = async (task: Task, nextDescription: string | null) => {
    // Optimistic description update.
    const previous = tasks;
    setTasks(
      tasks.map((t) =>
        t["@id"] === task["@id"] ? { ...t, description: nextDescription } : t,
      ),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ description: nextDescription }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to update description.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update description.");
    }
  };

  const handleDueDateChange = async (task: Task, nextDueDate: string | null) => {
    // Optimistic due-date update; rollback on server reject.
    const previous = tasks;
    setTasks(
      tasks.map((t) => (t["@id"] === task["@id"] ? { ...t, dueDate: nextDueDate } : t)),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ dueDate: nextDueDate }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to update due date.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update due date.");
    }
  };

  const handleTagsChange = async (task: Task, nextTagIris: string[]) => {
    // Optimistic update so badges appear instantly. Roll back on server reject.
    const previous = tasks;
    const nextTags = nextTagIris
      .map((iri) => allTags.find((t) => t["@id"] === iri))
      .filter((t): t is Tag => Boolean(t));
    setTasks(tasks.map((t) => (t["@id"] === task["@id"] ? { ...t, tags: nextTags } : t)));
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ tags: nextTagIris }),
      });
      if (!res.ok) {
        throw new Error("Failed to update tags.");
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update tags.");
    }
  };

  const handleDragEnd = async (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;

    const oldIndex = tasks.findIndex((t) => t["@id"] === active.id);
    const newIndex = tasks.findIndex((t) => t["@id"] === over.id);
    if (oldIndex === -1 || newIndex === -1) return;

    const previous = tasks;
    const reordered = arrayMove(tasks, oldIndex, newIndex);
    // Apply optimistically — snappy UX, rolled back below if the server rejects.
    setTasks(reordered);
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}/tasks/reorder`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ order: reordered.map((t) => t["@id"]) }),
      });
      if (!res.ok) {
        throw new Error("Failed to save new order.");
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to save new order.");
    }
  };

  const handleSort = (key: SortKey) => {
    setSort((current) => {
      // Cycle: same column asc → desc → back to manual default.
      if (current.key !== key) return { key, dir: "asc" };
      if (current.dir === "asc") return { key, dir: "desc" };
      return DEFAULT_SORT;
    });
  };

  // Sorted *view* of the tasks. Manual order leaves them as-is so the dnd-kit
  // index math stays aligned with what's painted; any other sort produces a
  // shallow copy ordered by the picked column.
  const visibleTasks = useMemo(() => {
    if (sort.key === "manual") return tasks;
    const flip = sort.dir === "asc" ? 1 : -1;
    const copy = [...tasks];
    copy.sort((a, b) => {
      switch (sort.key) {
        case "completed":
          // Sort completed flag first — undone tasks sort before done.
          return ((a.completedOn ? 1 : 0) - (b.completedOn ? 1 : 0)) * flip;
        case "title":
          return a.title.localeCompare(b.title) * flip;
        case "due": {
          // Null due dates always sort to the end regardless of direction.
          const aMissing = !a.dueDate;
          const bMissing = !b.dueDate;
          if (aMissing && bMissing) return 0;
          if (aMissing) return 1;
          if (bMissing) return -1;
          return a.dueDate!.localeCompare(b.dueDate!) * flip;
        }
        default:
          return 0;
      }
    });
    return copy;
  }, [tasks, sort]);

  const reorderable = sort.key === "manual";

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
        <title>Tasks - Aura</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-7xl mx-auto">
          <h1 className="text-2xl font-bold mb-6">Tasks</h1>

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {!reorderable && (
            <p
              className="mb-2 text-xs text-muted-foreground"
              data-testid="reorder-disabled-hint"
            >
              Drag-to-reorder is paused while the table is sorted by a column. Click
              the active column header again to return to your custom order.
            </p>
          )}

          {isLoading ? (
            <p className="text-muted-foreground">Loading tasks...</p>
          ) : (
            <Card>
              <CardContent className="p-0">
                <DndContext
                  sensors={sensors}
                  collisionDetection={closestCenter}
                  onDragEnd={handleDragEnd}
                >
                  <SortableContext
                    items={visibleTasks.map((t) => t["@id"])}
                    strategy={verticalListSortingStrategy}
                  >
                    <Table data-testid="task-list">
                      <TableHeader>
                        <TableRow>
                          <TableHead className="w-8" />
                          <SortableHeader
                            label="Done"
                            sortKey="completed"
                            active={sort}
                            onSort={handleSort}
                            className="w-16"
                          />
                          <SortableHeader
                            label="Title"
                            sortKey="title"
                            active={sort}
                            onSort={handleSort}
                          />
                          <SortableHeader
                            label="Due"
                            sortKey="due"
                            active={sort}
                            onSort={handleSort}
                            className="w-36"
                          />
                          <TableHead>Tags</TableHead>
                          <TableHead className="w-20 text-right">Actions</TableHead>
                        </TableRow>
                      </TableHeader>
                      <NewTaskRow
                        allTags={allTags}
                        onCreate={handleCreate}
                        isCreating={isSubmitting}
                      />
                      {visibleTasks.map((task) => (
                        <TaskRow
                          key={task["@id"]}
                          task={task}
                          allTags={allTags}
                          reorderable={reorderable}
                          onToggle={handleToggle}
                          onDelete={handleDelete}
                          onTagsChange={handleTagsChange}
                          onTitleChange={handleTitleChange}
                          onDescriptionChange={handleDescriptionChange}
                          onDueDateChange={handleDueDateChange}
                        />
                      ))}
                    </Table>
                  </SortableContext>
                </DndContext>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </>
  );
};

export default Tasks;
