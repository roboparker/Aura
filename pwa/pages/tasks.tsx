import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  Filter,
  GripVertical,
  Repeat,
  Trash2,
  X,
} from "lucide-react";
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
import AssigneesCombobox, {
  type AssigneeOption,
} from "@/components/tasks/AssigneesCombobox";
import TagsCombobox from "@/components/tasks/TagsCombobox";
import UserAvatar from "@/components/user/UserAvatar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import { Card, CardContent } from "@/components/ui/card";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { displayName } from "@/lib/userDisplay";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
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

type RecurrenceFrequency = "daily" | "weekly" | "monthly" | "yearly";

interface RecurrenceRule {
  frequency: RecurrenceFrequency;
  interval: number;
}

interface Task {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  completedOn: string | null;
  dueDate: string | null;
  recurrenceRule: RecurrenceRule | null;
  position: number;
  tags: Tag[];
  assignees: AssigneeOption[];
  // The API serializes Task.project as a bare IRI string under `task:read`.
  // null means "personal task" — only the owner is assignable.
  project: string | null;
}

const FREQUENCY_LABELS: Record<RecurrenceFrequency, string> = {
  daily: "day",
  weekly: "week",
  monthly: "month",
  yearly: "year",
};

const formatRecurrenceSummary = (rule: RecurrenceRule): string => {
  const noun = FREQUENCY_LABELS[rule.frequency];
  if (rule.interval === 1) return `Every ${noun}`;
  return `Every ${rule.interval} ${noun}s`;
};

interface ProjectMembership {
  "@id": string;
  members: Array<{ "@id": string }>;
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

// Stored as ISO datetime on the wire but only the calendar day matters —
// we persist UTC midnight on the picked day so round-trips are stable
// across timezones. Read back via the YYYY-MM-DD slice and rebuild as a
// local Date so the calendar / formatter render the day the user picked.
const isoToLocalDate = (iso: string | null): Date | undefined => {
  if (!iso) return undefined;
  const [year, month, day] = iso.slice(0, 10).split("-").map(Number);
  if (!year || !month || !day) return undefined;
  return new Date(year, month - 1, day);
};

const localDateToIso = (date: Date): string => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}T00:00:00+00:00`;
};

const dueDateFormatter = new Intl.DateTimeFormat(undefined, {
  year: "numeric",
  month: "short",
  day: "numeric",
});

const formatDueDate = (iso: string | null): string => {
  const date = isoToLocalDate(iso);
  return date ? dueDateFormatter.format(date) : "";
};

// Local-midnight "today" so day comparisons line up with the dates the picker
// stores (also local-midnight). Cheaper than a fresh Date() per row.
const todayLocalMidnight = (): Date => {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), now.getDate());
};

type DueDateStatus = "overdue" | "today" | "future" | "none";

const dueDateStatus = (iso: string | null, completed: boolean): DueDateStatus => {
  if (completed || !iso) return "none";
  const due = isoToLocalDate(iso);
  if (!due) return "none";
  const today = todayLocalMidnight();
  if (due.getTime() < today.getTime()) return "overdue";
  if (due.getTime() === today.getTime()) return "today";
  return "future";
};

const addDays = (date: Date, days: number): Date => {
  const next = new Date(date);
  next.setDate(next.getDate() + days);
  return next;
};

const addMonths = (date: Date, months: number): Date => {
  const next = new Date(date);
  next.setMonth(next.getMonth() + months);
  return next;
};

interface DueDateCellProps {
  value: string | null;
  onChange: (next: string | null) => void | Promise<void>;
  ariaLabel: string;
  testIdPrefix: string;
  /** Optional recurrence controls. When `recurrenceValue` is provided we render
   *  a frequency + interval picker in the same popover; clearing the date
   *  also clears the rule (a recurrence with no anchor is invalid server-side). */
  recurrenceValue?: RecurrenceRule | null;
  onRecurrenceChange?: (next: RecurrenceRule | null) => void | Promise<void>;
  /** Toggles overdue/today colouring. Completed tasks pass `"none"` so a missed
   *  deadline doesn't keep glowing red after the work is done. */
  status?: DueDateStatus;
}

const DueDateCell = ({
  value,
  onChange,
  ariaLabel,
  testIdPrefix,
  recurrenceValue = null,
  onRecurrenceChange,
  status = "none",
}: DueDateCellProps) => {
  const [open, setOpen] = useState(false);
  const selected = isoToLocalDate(value);

  const handleSelect = (date: Date | undefined) => {
    setOpen(false);
    const next = date ? localDateToIso(date) : null;
    if (next === value) return;
    void onChange(next);
  };

  const handleQuickPick = (date: Date) => {
    setOpen(false);
    const next = localDateToIso(date);
    if (next === value) return;
    void onChange(next);
  };

  const handleClear = () => {
    setOpen(false);
    if (value === null) return;
    // Recurrence is meaningless without a date anchor; drop it together to
    // avoid leaving the row in a state the server-side validator rejects.
    if (recurrenceValue && onRecurrenceChange) {
      void onRecurrenceChange(null);
    }
    void onChange(null);
  };

  const today = todayLocalMidnight();
  const quickPicks: Array<{ label: string; date: Date; testId: string }> = [
    { label: "Today", date: today, testId: "today" },
    { label: "Tomorrow", date: addDays(today, 1), testId: "tomorrow" },
    { label: "Next week", date: addDays(today, 7), testId: "next-week" },
    { label: "Next month", date: addMonths(today, 1), testId: "next-month" },
  ];

  const dateClassName = cn(
    "text-left text-sm rounded-sm inline-flex items-center gap-1 hover:text-foreground",
    status === "overdue" && "text-destructive font-medium hover:text-destructive",
    status === "today" && "text-amber-600 dark:text-amber-400 font-medium",
  );

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        {value ? (
          <button
            type="button"
            aria-label={
              status === "overdue"
                ? `${ariaLabel} (overdue)`
                : status === "today"
                  ? `${ariaLabel} (due today)`
                  : ariaLabel
            }
            className={dateClassName}
            data-testid={testIdPrefix}
            data-status={status}
          >
            {status === "overdue" && (
              <AlertTriangle
                className="h-3.5 w-3.5"
                aria-hidden="true"
                data-testid={`${testIdPrefix}-overdue-icon`}
              />
            )}
            <span>{formatDueDate(value)}</span>
            {recurrenceValue && (
              <Repeat
                className="h-3 w-3 text-muted-foreground"
                aria-label={formatRecurrenceSummary(recurrenceValue)}
                data-testid={`${testIdPrefix}-repeat-icon`}
              />
            )}
          </button>
        ) : (
          <button
            type="button"
            aria-label={ariaLabel}
            className="text-left text-sm italic text-muted-foreground/60 hover:text-muted-foreground rounded-sm"
            data-testid={`${testIdPrefix}-add`}
          >
            Add date
          </button>
        )}
      </PopoverTrigger>
      <PopoverContent
        className="w-auto p-0"
        align="start"
        data-testid={`${testIdPrefix}-popover`}
      >
        {/* Quick-picks above the calendar — covers the common case of "soon"
            without making the user navigate the grid. */}
        <div className="grid grid-cols-2 gap-1 border-b p-2">
          {quickPicks.map((pick) => (
            <Button
              key={pick.testId}
              type="button"
              variant="ghost"
              size="sm"
              className="justify-start"
              onClick={() => handleQuickPick(pick.date)}
              data-testid={`${testIdPrefix}-quick-${pick.testId}`}
            >
              {pick.label}
            </Button>
          ))}
        </div>
        <Calendar
          mode="single"
          selected={selected}
          onSelect={handleSelect}
          autoFocus
        />
        {onRecurrenceChange && value && (
          <RecurrencePicker
            value={recurrenceValue}
            onChange={onRecurrenceChange}
            testIdPrefix={`${testIdPrefix}-recurrence`}
          />
        )}
        {value && (
          <div className="border-t p-2">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="w-full justify-center"
              onClick={handleClear}
              data-testid={`${testIdPrefix}-clear`}
            >
              Clear
            </Button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
};

interface RecurrencePickerProps {
  value: RecurrenceRule | null;
  onChange: (next: RecurrenceRule | null) => void | Promise<void>;
  testIdPrefix: string;
}

// Inline recurrence controls — renders inside the date popover so users
// don't have to context-switch to set a repeat. "Off" clears the rule;
// otherwise the (frequency, interval) pair is sent as a single update.
const RecurrencePicker = ({ value, onChange, testIdPrefix }: RecurrencePickerProps) => {
  const frequency: "off" | RecurrenceFrequency = value?.frequency ?? "off";
  const interval = value?.interval ?? 1;

  const handleFrequencyChange = (next: string) => {
    if (next === "off") {
      void onChange(null);
      return;
    }
    void onChange({ frequency: next as RecurrenceFrequency, interval });
  };

  const handleIntervalChange = (raw: string) => {
    const parsed = Number.parseInt(raw, 10);
    if (!Number.isFinite(parsed) || parsed < 1) return;
    if (!value) return;
    void onChange({ frequency: value.frequency, interval: parsed });
  };

  return (
    <div className="border-t p-2 space-y-2 min-w-56">
      <div className="flex items-center gap-2 text-sm">
        <Repeat className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
        <label
          htmlFor={`${testIdPrefix}-frequency`}
          className="text-muted-foreground"
        >
          Repeat
        </label>
        <select
          id={`${testIdPrefix}-frequency`}
          value={frequency}
          onChange={(e) => handleFrequencyChange(e.target.value)}
          className="ml-auto h-8 rounded-md border border-input bg-background px-2 text-sm"
          data-testid={`${testIdPrefix}-frequency`}
        >
          <option value="off">Off</option>
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="monthly">Monthly</option>
          <option value="yearly">Yearly</option>
        </select>
      </div>
      {value && (
        <div className="flex items-center gap-2 text-sm">
          <label
            htmlFor={`${testIdPrefix}-interval`}
            className="text-muted-foreground"
          >
            Every
          </label>
          <Input
            id={`${testIdPrefix}-interval`}
            type="number"
            min={1}
            max={99}
            value={interval}
            onChange={(e) => handleIntervalChange(e.target.value)}
            className="h-8 w-16"
            data-testid={`${testIdPrefix}-interval`}
          />
          <span className="text-muted-foreground">
            {FREQUENCY_LABELS[value.frequency]}
            {interval === 1 ? "" : "s"}
          </span>
        </div>
      )}
    </div>
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
  assignableUsers: AssigneeOption[];
  reorderable: boolean;
  onToggle: (task: Task) => void;
  onDelete: (task: Task) => void;
  onTagsChange: (task: Task, nextTagIris: string[]) => Promise<void>;
  onTitleChange: (task: Task, nextTitle: string) => Promise<void>;
  onDescriptionChange: (task: Task, nextDescription: string | null) => Promise<void>;
  onDueDateChange: (task: Task, nextDueDate: string | null) => Promise<void>;
  onRecurrenceChange: (task: Task, nextRule: RecurrenceRule | null) => Promise<void>;
  onAssigneesChange: (task: Task, nextIris: string[]) => Promise<void>;
  onAssigneeAvatarClick: (assignee: AssigneeOption) => void;
}

const TaskRow = ({
  task,
  allTags,
  assignableUsers,
  reorderable,
  onToggle,
  onDelete,
  onTagsChange,
  onTitleChange,
  onDescriptionChange,
  onDueDateChange,
  onRecurrenceChange,
  onAssigneesChange,
  onAssigneeAvatarClick,
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
            recurrenceValue={task.recurrenceRule}
            onRecurrenceChange={(next) => onRecurrenceChange(task, next)}
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
  assignees: string[];
}

interface NewTaskRowProps {
  allTags: Tag[];
  assignableUsers: AssigneeOption[];
  /** Resolves on success, rejects on failure so we know whether to clear the draft. */
  onCreate: (input: NewTaskInput) => Promise<void>;
  isCreating: boolean;
  currentUserIri: string | null;
  /** When true, new tasks default to being assigned to the current user — used
   *  on /my-tasks so a freshly created row doesn't immediately filter itself
   *  out. */
  autoAssignSelf?: boolean;
}

// Inline "add a task" row that lives at the top of the table. Mirrors the
// layout of TaskRow (main row + description sub-row) so the user can stage
// title, tags, and description before pressing Enter to submit. Failures
// keep the draft intact (parent shows the error).
const NewTaskRow = ({
  allTags,
  assignableUsers,
  onCreate,
  isCreating,
  currentUserIri,
  autoAssignSelf,
}: NewTaskRowProps) => {
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState<string | null>(null);
  const [tags, setTags] = useState<Tag[]>([]);
  const [dueDate, setDueDate] = useState<string | null>(null);
  // Personal tasks (no project) only allow the owner. Restrict the picker to
  // self so we don't surface teammates the validator would reject.
  const newTaskAssignableUsers = useMemo(
    () => assignableUsers.filter((u) => u["@id"] === currentUserIri),
    [assignableUsers, currentUserIri],
  );
  const selfOption = newTaskAssignableUsers[0] ?? null;
  const [assignees, setAssignees] = useState<AssigneeOption[]>(() =>
    autoAssignSelf && selfOption ? [selfOption] : [],
  );
  // If the assignable list arrives after mount (async), seed the default
  // assignment then. Subsequent changes are user-driven, so only seed once.
  const seededRef = useRef(false);
  useEffect(() => {
    if (seededRef.current) return;
    if (autoAssignSelf && selfOption && assignees.length === 0) {
      setAssignees([selfOption]);
      seededRef.current = true;
    }
  }, [autoAssignSelf, selfOption, assignees.length]);
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

  const handleAssigneesChange = (nextIris: string[]) => {
    const next = nextIris
      .map((iri) => assignableUsers.find((u) => u["@id"] === iri))
      .filter((u): u is AssigneeOption => Boolean(u));
    setAssignees(next);
  };

  const reset = () => {
    setTitle("");
    setDescription(null);
    setTags([]);
    setDueDate(null);
    setAssignees(autoAssignSelf && selfOption ? [selfOption] : []);
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
        assignees: assignees.map((u) => u["@id"]),
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
        <TableCell className="align-top" data-testid="new-task-assignees">
          <AssigneesCombobox
            value={assignees}
            options={newTaskAssignableUsers}
            onChange={handleAssigneesChange}
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
        <TableCell colSpan={5} className="pl-0 pr-4 pt-0 pb-3 text-sm">
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

// "all" shows every task; "me" filters to the current user; an IRI string
// filters to that specific assignable user. Stored as a single value rather
// than three separate flags so each setter call replaces the previous filter.
type AssigneeFilter = "all" | "me" | string;

const Tasks = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const currentUserIri = user ? `/users/${user.id}` : null;
  // Both `/tasks` and `/my-tasks` mount this component. The latter pins the
  // assignee filter to the logged-in user and hides the picker, so the page
  // is effectively a fixed "everything assigned to me" view.
  const isMyTasksPage = router.pathname === "/my-tasks";
  const pageTitle = isMyTasksPage ? "My Tasks" : "Tasks";

  const [tasks, setTasks] = useState<Task[]>([]);
  const [allTags, setAllTags] = useState<Tag[]>([]);
  const [assignableUsers, setAssignableUsers] = useState<AssigneeOption[]>([]);
  // Map of project IRI → set of member IRIs. Used to filter the assignee
  // picker per task (project tasks accept only that project's members; the
  // server-side validator enforces the same rule).
  const [projectMembers, setProjectMembers] = useState<Map<string, Set<string>>>(
    new Map(),
  );
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [sort, setSort] = useState<SortState>(DEFAULT_SORT);
  const [assigneeFilter, setAssigneeFilter] = useState<AssigneeFilter>(
    isMyTasksPage ? "me" : "all",
  );
  const [overdueOnly, setOverdueOnly] = useState(false);

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
      // Tasks, tags, the assignable-users universe, and the projects-with-
      // members set all load in parallel — the page needs the projects to
      // know which assignable users are valid for each project task.
      const [tasksRes, tagsRes, assignablesRes, projectsRes] = await Promise.all([
        fetch(`${ENTRYPOINT}/tasks`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
        fetch(`${ENTRYPOINT}/tags`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
        fetch(`${ENTRYPOINT}/me/assignable-users`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
        fetch(`${ENTRYPOINT}/projects`, {
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
      if (!assignablesRes.ok) {
        throw new Error("Failed to load assignable users.");
      }
      if (!projectsRes.ok) {
        throw new Error("Failed to load projects.");
      }
      const tasksData: Collection<Task> = await tasksRes.json();
      const tagsData: Collection<Tag> = await tagsRes.json();
      const assignablesData: Collection<AssigneeOption> = await assignablesRes.json();
      const projectsData: Collection<ProjectMembership> = await projectsRes.json();
      setTasks(tasksData.member ?? tasksData["hydra:member"] ?? []);
      setAllTags(tagsData.member ?? tagsData["hydra:member"] ?? []);
      setAssignableUsers(
        assignablesData.member ?? assignablesData["hydra:member"] ?? [],
      );
      const projectMap = new Map<string, Set<string>>();
      const projects = projectsData.member ?? projectsData["hydra:member"] ?? [];
      for (const project of projects) {
        projectMap.set(
          project["@id"],
          new Set(project.members.map((m) => m["@id"])),
        );
      }
      setProjectMembers(projectMap);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load tasks.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  // /tasks and /my-tasks render the *same* component instance via the
  // re-export in pages/my-tasks.tsx, so the `useState` initializer above
  // only runs once. Without this effect the filter (and the cached tasks
  // list) would carry over from the previous route. Re-pin the filter and
  // refetch whenever the page mode flips — this also covers the initial
  // mount once `isAuthenticated` becomes true.
  useEffect(() => {
    setAssigneeFilter(isMyTasksPage ? "me" : "all");
    if (isAuthenticated) {
      loadData();
    }
  }, [isMyTasksPage, isAuthenticated, loadData]);

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
          assignees: input.assignees,
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
    // Completing a recurring task spawns a fresh occurrence server-side; we
    // need to refetch so that new row appears in the list. Toggling *off*
    // (un-completing) doesn't need a refetch.
    const willSpawnNextOccurrence =
      !task.completedOn && task.recurrenceRule !== null && task.dueDate !== null;
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
      if (willSpawnNextOccurrence) {
        await loadData();
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

  const handleRecurrenceChange = async (
    task: Task,
    nextRule: RecurrenceRule | null,
  ) => {
    const previous = tasks;
    setTasks(
      tasks.map((t) =>
        t["@id"] === task["@id"] ? { ...t, recurrenceRule: nextRule } : t,
      ),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ recurrenceRule: nextRule }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to update recurrence.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update recurrence.");
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

  const handleAssigneesChange = async (task: Task, nextIris: string[]) => {
    const previous = tasks;
    const nextAssignees = nextIris
      .map((iri) => assignableUsers.find((u) => u["@id"] === iri))
      .filter((u): u is AssigneeOption => Boolean(u));
    setTasks(
      tasks.map((t) =>
        t["@id"] === task["@id"] ? { ...t, assignees: nextAssignees } : t,
      ),
    );
    setError(null);

    try {
      const res = await fetch(`${ENTRYPOINT}${task["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ assignees: nextIris }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to update assignees.",
        );
      }
    } catch (err) {
      setTasks(previous);
      setError(err instanceof Error ? err.message : "Failed to update assignees.");
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

  // Count of overdue tasks across the current assignee scope (so the chip
  // shows e.g. "3 overdue" when "Assigned to me" is active). Computed off the
  // assignee-filtered list, *before* the overdue filter is applied.
  const assigneeFilteredTasks = useMemo(() => {
    if (assigneeFilter === "all") return tasks;
    const targetIri = assigneeFilter === "me" ? currentUserIri : assigneeFilter;
    if (!targetIri) return tasks;
    return tasks.filter((t) => t.assignees.some((a) => a["@id"] === targetIri));
  }, [tasks, assigneeFilter, currentUserIri]);

  const overdueCount = useMemo(
    () =>
      assigneeFilteredTasks.filter(
        (t) => dueDateStatus(t.dueDate, !!t.completedOn) === "overdue",
      ).length,
    [assigneeFilteredTasks],
  );

  // Apply the assignee filter, then the overdue toggle, then sort. "all"
  // leaves tasks intact so the manual-order drag math stays aligned.
  const filteredTasks = useMemo(() => {
    if (!overdueOnly) return assigneeFilteredTasks;
    return assigneeFilteredTasks.filter(
      (t) => dueDateStatus(t.dueDate, !!t.completedOn) === "overdue",
    );
  }, [assigneeFilteredTasks, overdueOnly]);

  const visibleTasks = useMemo(() => {
    if (sort.key === "manual") return filteredTasks;
    const flip = sort.dir === "asc" ? 1 : -1;
    const copy = [...filteredTasks];
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
  }, [filteredTasks, sort]);

  // Reordering is only safe in the "manual" sort *and* when nothing is being
  // filtered out — drag-end math assumes the index lines up with the
  // persisted position.
  const reorderable =
    sort.key === "manual" && assigneeFilter === "all" && !overdueOnly;

  const assignableForTask = useCallback(
    (task: Task): AssigneeOption[] => {
      if (!task.project) {
        // Personal task — only the owner can be assigned. The owner is
        // always in the assignable-users set when it's the current user; for
        // admin-viewed tasks owned by others, fall back to the task's
        // current assignees (already known-valid).
        const owner = task.assignees.find((a) => a["@id"] === currentUserIri);
        return owner ? [owner] : task.assignees;
      }
      const memberIris = projectMembers.get(task.project);
      if (!memberIris) return assignableUsers;
      return assignableUsers.filter((u) => memberIris.has(u["@id"]));
    },
    [assignableUsers, projectMembers, currentUserIri],
  );

  // Users that have ever been assigned to a task on the page — drives the
  // "specific person" entries in the filter dropdown so we don't list
  // everyone the API would accept.
  const filterableUsers = useMemo(() => {
    const seen = new Map<string, AssigneeOption>();
    for (const t of tasks) {
      for (const a of t.assignees) {
        if (!seen.has(a["@id"])) seen.set(a["@id"], a);
      }
    }
    return Array.from(seen.values());
  }, [tasks]);

  const filterLabel = useMemo(() => {
    if (assigneeFilter === "all") return "All assignees";
    if (assigneeFilter === "me") return "Assigned to me";
    const match = filterableUsers.find((u) => u["@id"] === assigneeFilter);
    return match ? `Assigned to ${displayName(match)}` : "Filtered";
  }, [assigneeFilter, filterableUsers]);

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
        <title>{pageTitle} - Aura</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-7xl mx-auto">
          <div className="flex items-center justify-between mb-6 gap-3 flex-wrap">
            <h1 className="text-2xl font-bold">{pageTitle}</h1>
            <div className="flex items-center gap-2 flex-wrap">
              {/* Overdue chip is always available — it's useful on /my-tasks
                  even though the assignee dropdown is hidden there. The chip
                  is disabled when there's nothing overdue so users still see
                  the zero-state at a glance. */}
              <Button
                variant={overdueOnly ? "default" : "outline"}
                size="sm"
                onClick={() => setOverdueOnly((v) => !v)}
                disabled={overdueCount === 0 && !overdueOnly}
                aria-pressed={overdueOnly}
                data-testid="overdue-filter-toggle"
              >
                <AlertTriangle className="h-3.5 w-3.5 mr-1" />
                Overdue
                <span
                  className={cn(
                    "ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold",
                    overdueOnly
                      ? "bg-primary-foreground/20 text-primary-foreground"
                      : overdueCount > 0
                        ? "bg-destructive text-destructive-foreground"
                        : "bg-muted text-muted-foreground",
                  )}
                  data-testid="overdue-filter-count"
                >
                  {overdueCount}
                </span>
              </Button>
              {/* The assignee dropdown is redundant on /my-tasks (everything
                  shown is already filtered to the current user). */}
              {!isMyTasksPage && (
              <>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    data-testid="assignee-filter-trigger"
                  >
                    <Filter className="h-3.5 w-3.5 mr-1" />
                    {filterLabel}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                  <DropdownMenuLabel>Filter by assignee</DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  <DropdownMenuCheckboxItem
                    checked={assigneeFilter === "all"}
                    onCheckedChange={() => setAssigneeFilter("all")}
                  >
                    All assignees
                  </DropdownMenuCheckboxItem>
                  <DropdownMenuCheckboxItem
                    checked={assigneeFilter === "me"}
                    onCheckedChange={() => setAssigneeFilter("me")}
                    disabled={!currentUserIri}
                  >
                    Assigned to me
                  </DropdownMenuCheckboxItem>
                  {filterableUsers.length > 0 && <DropdownMenuSeparator />}
                  {filterableUsers.map((u) => (
                    <DropdownMenuCheckboxItem
                      key={u["@id"]}
                      checked={assigneeFilter === u["@id"]}
                      onCheckedChange={() => setAssigneeFilter(u["@id"])}
                    >
                      <span className="flex items-center gap-2 truncate">
                        <UserAvatar user={u} size="sm" className="h-5 w-5" />
                        <span className="truncate">{displayName(u)}</span>
                      </span>
                    </DropdownMenuCheckboxItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
              {assigneeFilter !== "all" && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setAssigneeFilter("all")}
                  aria-label="Clear assignee filter"
                  data-testid="assignee-filter-clear"
                >
                  <X className="h-3.5 w-3.5" />
                </Button>
              )}
              </>
              )}
            </div>
          </div>

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
              Drag-to-reorder is paused while the list is filtered or sorted by a
              column. Clear the filter and reset the column header to return to
              your custom order.
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
                          <TableHead>Assignees</TableHead>
                          <TableHead className="w-20 text-right">Actions</TableHead>
                        </TableRow>
                      </TableHeader>
                      <NewTaskRow
                        allTags={allTags}
                        assignableUsers={assignableUsers}
                        onCreate={handleCreate}
                        isCreating={isSubmitting}
                        currentUserIri={currentUserIri}
                        autoAssignSelf={isMyTasksPage}
                      />
                      {visibleTasks.map((task) => (
                        <TaskRow
                          key={task["@id"]}
                          task={task}
                          allTags={allTags}
                          assignableUsers={assignableForTask(task)}
                          reorderable={reorderable}
                          onToggle={handleToggle}
                          onDelete={handleDelete}
                          onTagsChange={handleTagsChange}
                          onTitleChange={handleTitleChange}
                          onDescriptionChange={handleDescriptionChange}
                          onDueDateChange={handleDueDateChange}
                          onRecurrenceChange={handleRecurrenceChange}
                          onAssigneesChange={handleAssigneesChange}
                          onAssigneeAvatarClick={(assignee) =>
                            setAssigneeFilter(assignee["@id"])
                          }
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
