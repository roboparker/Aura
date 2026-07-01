import { useState } from "react";
import {
  DndContext,
  DragOverlay,
  PointerSensor,
  closestCenter,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
  type CollisionDetection,
  type DragEndEvent,
  type DragStartEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  horizontalListSortingStrategy,
  useSortable,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { Check, GripVertical, MoreHorizontal, Plus, Trash2 } from "lucide-react";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import AssigneePlaceholder from "@/components/user/AssigneePlaceholder";
import { displayName } from "@/lib/userDisplay";
import { Badge } from "@/components/ui/badge";
import CustomFieldValueCell from "@/components/custom-fields/CustomFieldValueCell";
import type { CustomFieldDefinition } from "@/components/custom-fields/types";
import type { CustomFieldValuePair } from "@/components/tasks/CustomFieldValueList";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";

/**
 * Kanban board view for a project: one column per board section, with task
 * cards that open the detail drawer on click and drag between sections. The
 * default "In progress" group (sectionIri === null) is the first column.
 *
 * Drag uses @dnd-kit with a pointer activation distance so a plain click still
 * opens the drawer; dropping a card on a column moves the task to that section.
 */
/** An assignee/assignable user carrying its IRI, so the assign menu can
 *  toggle membership and PATCH the task's assignee IRIs. */
export type BoardUser = AvatarUser & { "@id": string };

export interface BoardTask {
  "@id": string;
  id: string;
  title: string;
  completedOn: string | null;
  dueDate: string | null;
  section: string | null;
  tags: { "@id": string; title: string }[];
  assignees: BoardUser[];
  customFieldValues: CustomFieldValuePair[];
}

export interface BoardColumn {
  key: string;
  sectionIri: string | null;
  title: string;
  tasks: BoardTask[];
}

interface TaskBoardProps {
  columns: BoardColumn[];
  /** Custom fields to surface on cards (already filtered to board visibility). */
  definitions: CustomFieldDefinition[];
  /** Everyone assignable on this project, for the per-card assign menu. */
  assignableUsers: BoardUser[];
  onOpen: (taskIri: string) => void;
  onMove: (taskIri: string, sectionIri: string | null) => void;
  /** Reorder columns: the dragged column key and the column it was dropped on. */
  onReorderSections: (activeKey: string, overKey: string) => void;
  onAssign: (taskIri: string, userIris: string[]) => void | Promise<void>;
  onAddTask: (sectionIri: string | null) => void;
  onAddSection: (title: string) => void;
  onDeleteSection: (sectionIri: string) => void;
}

const COL_PREFIX = "col:";
const SECTION_PREFIX = "section:";

/**
 * Restrict collisions by drag kind: a column drag (`section:`) only sees the
 * other columns' sortable nodes (so they shift to open the placeholder gap and
 * reorder resolves), while a card drag only sees the columns' card-drop areas
 * (`col:`) so the drop-target highlight and move still work.
 */
const boardCollision: CollisionDetection = (args) => {
  const wantSection = String(args.active.id).startsWith(SECTION_PREFIX);
  return closestCenter({
    ...args,
    droppableContainers: args.droppableContainers.filter((c) => {
      const id = String(c.id);
      return wantSection
        ? id.startsWith(SECTION_PREFIX)
        : id.startsWith(COL_PREFIX);
    }),
  });
};

const dueLabel = (iso: string): string => {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
};

const isEmptyValue = (v: unknown): boolean =>
  v === null ||
  v === undefined ||
  v === "" ||
  (Array.isArray(v) && v.length === 0);

const CardCustomFields = ({
  task,
  definitions,
}: {
  task: BoardTask;
  definitions: CustomFieldDefinition[];
}) => {
  const chips = definitions
    .map((def) => ({
      def,
      value: task.customFieldValues.find((v) => v.definition === def["@id"])
        ?.value,
    }))
    .filter(({ value }) => !isEmptyValue(value));
  if (chips.length === 0) return null;
  return (
    <div className="flex flex-wrap gap-1">
      {chips.map(({ def, value }) => (
        <span
          key={def["@id"]}
          className="inline-flex items-center gap-1 rounded border bg-muted/40 px-1.5 py-0.5 text-xs"
          data-testid="board-card-field"
        >
          <span className="text-muted-foreground">{def.name}</span>
          <CustomFieldValueCell
            definition={def}
            value={value}
            className="text-xs font-medium"
          />
        </span>
      ))}
    </div>
  );
};

/**
 * Assignee cluster + picker for a card. Shows the assignees' avatars, or an
 * "anonymous" placeholder avatar when no one is assigned; either opens a menu
 * to toggle who's assigned. Stops pointer/click propagation so it neither
 * starts a card drag nor opens the detail drawer.
 */
const AssignMenu = ({
  task,
  assignableUsers,
  onAssign,
}: {
  task: BoardTask;
  assignableUsers: BoardUser[];
  onAssign: (taskIri: string, userIris: string[]) => void | Promise<void>;
}) => {
  const assignedIris = new Set(task.assignees.map((a) => a["@id"]));
  const toggle = (iri: string) => {
    const next = assignedIris.has(iri)
      ? task.assignees.map((a) => a["@id"]).filter((i) => i !== iri)
      : [...task.assignees.map((a) => a["@id"]), iri];
    void onAssign(task["@id"], next);
  };
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          onClick={(e) => e.stopPropagation()}
          onPointerDown={(e) => e.stopPropagation()}
          className="ml-auto flex items-center rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          aria-label={
            task.assignees.length > 0 ? "Change assignees" : "Assign someone"
          }
          data-testid="board-card-assign"
        >
          {task.assignees.length > 0 ? (
            <span className="flex -space-x-1.5">
              {task.assignees.slice(0, 3).map((a) => (
                <UserAvatar
                  key={a["@id"]}
                  user={a}
                  size="sm"
                  className="ring-2 ring-card"
                />
              ))}
            </span>
          ) : (
            <AssigneePlaceholder className="hover:border-foreground/40 hover:text-foreground" />
          )}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent
        align="end"
        className="max-h-64 overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        {assignableUsers.length === 0 ? (
          <DropdownMenuItem disabled>No one to assign</DropdownMenuItem>
        ) : (
          assignableUsers.map((u) => (
            <DropdownMenuItem
              key={u["@id"]}
              onSelect={(e) => {
                e.preventDefault();
                toggle(u["@id"]);
              }}
              className="gap-2"
            >
              <UserAvatar user={u} size="sm" />
              <span className="min-w-0 flex-1 truncate">{displayName(u)}</span>
              {assignedIris.has(u["@id"]) && (
                <Check className="h-3.5 w-3.5 shrink-0" />
              )}
            </DropdownMenuItem>
          ))
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
};

const CardBody = ({
  task,
  definitions,
  assignableUsers,
  onAssign,
}: {
  task: BoardTask;
  definitions: CustomFieldDefinition[];
  assignableUsers: BoardUser[];
  onAssign: (taskIri: string, userIris: string[]) => void | Promise<void>;
}) => (
  <div className="space-y-2">
    <p
      className={cn(
        "text-sm leading-snug",
        task.completedOn && "text-muted-foreground",
      )}
    >
      {task.title}
    </p>
    <CardCustomFields task={task} definitions={definitions} />
    <div className="flex flex-wrap items-center gap-1">
      {task.dueDate && (
        <Badge variant="secondary" className="px-1.5 py-0 text-xs font-normal">
          {dueLabel(task.dueDate)}
        </Badge>
      )}
      {task.tags.map((tag) => (
        <Badge
          key={tag["@id"]}
          variant="outline"
          className="px-1.5 py-0 text-xs font-normal"
        >
          {tag.title}
        </Badge>
      ))}
      <AssignMenu
        task={task}
        assignableUsers={assignableUsers}
        onAssign={onAssign}
      />
    </div>
  </div>
);

const TaskCard = ({
  task,
  definitions,
  assignableUsers,
  onOpen,
  onAssign,
}: {
  task: BoardTask;
  definitions: CustomFieldDefinition[];
  assignableUsers: BoardUser[];
  onOpen: (taskIri: string) => void;
  onAssign: (taskIri: string, userIris: string[]) => void | Promise<void>;
}) => {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: task["@id"],
  });
  // A div (not a button) so the nested assign menu's button is valid markup
  // and its clicks don't bubble up to open the drawer. The dnd-kit attributes
  // give it role="button" + tabIndex; we add Enter/Space activation to match.
  return (
    <div
      ref={setNodeRef}
      {...attributes}
      {...listeners}
      onClick={() => onOpen(task["@id"])}
      onKeyDown={(e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          onOpen(task["@id"]);
        }
      }}
      className={cn(
        "w-full cursor-grab rounded-lg border bg-card p-3.5 text-left shadow-sm transition hover:border-foreground/20 active:cursor-grabbing",
        isDragging && "opacity-40",
      )}
      data-testid="board-card"
    >
      <CardBody
        task={task}
        definitions={definitions}
        assignableUsers={assignableUsers}
        onAssign={onAssign}
      />
    </div>
  );
};

const BoardColumnView = ({
  column,
  definitions,
  assignableUsers,
  onOpen,
  onAssign,
  onAddTask,
  onDeleteSection,
}: {
  column: BoardColumn;
  definitions: CustomFieldDefinition[];
  assignableUsers: BoardUser[];
  onOpen: (taskIri: string) => void;
  onAssign: (taskIri: string, userIris: string[]) => void | Promise<void>;
  onAddTask: (sectionIri: string | null) => void;
  onDeleteSection: (sectionIri: string) => void;
}) => {
  const { setNodeRef, isOver } = useDroppable({ id: `${COL_PREFIX}${column.key}` });
  // Columns are sortable (so the others shift to open a placeholder gap as one
  // is dragged, like the list view), but only the header grip activates the
  // drag — clicking cards / the menu / add-task still works. The card-drop area
  // keeps its own droppable ref (above).
  const {
    setNodeRef: setSortableRef,
    attributes,
    listeners,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: `${SECTION_PREFIX}${column.key}` });
  const sortableStyle = {
    transform: CSS.Transform.toString(transform),
    transition,
  };

  // While dragging, the column's slot becomes a dashed placeholder that the
  // sibling columns push around — the floating column itself is the DragOverlay.
  if (isDragging) {
    return (
      <div
        ref={setSortableRef}
        style={sortableStyle}
        className="w-80 shrink-0 rounded-xl border-2 border-dashed border-foreground/25 bg-muted/30"
        data-testid="board-column-placeholder"
        aria-hidden
      />
    );
  }

  return (
    <div
      ref={setSortableRef}
      style={sortableStyle}
      className="flex w-80 shrink-0 flex-col overflow-hidden rounded-xl border bg-muted/40"
      data-testid="board-column"
    >
      <div className="flex items-center gap-2 border-b bg-card px-3 py-2">
        <button
          type="button"
          {...attributes}
          {...listeners}
          className="-ml-1 cursor-grab touch-none rounded p-0.5 text-muted-foreground hover:text-foreground active:cursor-grabbing"
          aria-label={`Reorder section "${column.title}"`}
          data-testid="board-column-drag"
        >
          <GripVertical className="h-4 w-4" />
        </button>
        <span className="text-base font-bold tracking-tight text-foreground">
          {column.title}
        </span>
        <span className="rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
          {column.tasks.length}
        </span>
        {column.sectionIri && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                className="ml-auto rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                aria-label="Section actions"
              >
                <MoreHorizontal className="h-4 w-4" />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                onClick={() => onDeleteSection(column.sectionIri as string)}
                className="text-destructive"
              >
                <Trash2 className="mr-2 h-4 w-4" /> Delete section
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      </div>
      <div
        ref={setNodeRef}
        className={cn(
          "flex min-h-24 flex-1 flex-col gap-2 p-2 transition",
          isOver && "bg-foreground/5 ring-1 ring-inset ring-foreground/15",
        )}
      >
        {column.tasks.map((task) => (
          <TaskCard
            key={task["@id"]}
            task={task}
            definitions={definitions}
            assignableUsers={assignableUsers}
            onOpen={onOpen}
            onAssign={onAssign}
          />
        ))}
        <button
          type="button"
          onClick={() => onAddTask(column.sectionIri)}
          className="flex items-center gap-1 rounded-md px-1.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
          data-testid="board-add-task"
        >
          <Plus className="h-3.5 w-3.5" /> Add task
        </button>
      </div>
    </div>
  );
};

const TaskBoard = ({
  columns,
  definitions,
  assignableUsers,
  onOpen,
  onMove,
  onReorderSections,
  onAssign,
  onAddTask,
  onAddSection,
  onDeleteSection,
}: TaskBoardProps) => {
  const [draggingId, setDraggingId] = useState<string | null>(null);
  const [addingSection, setAddingSection] = useState(false);
  const [newSectionTitle, setNewSectionTitle] = useState("");
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
  );

  // Enter commits (create with the typed title); Escape / blur discards. The
  // section is only created on an explicit Enter, never on blur.
  const commitSection = () => {
    const title = newSectionTitle.trim();
    if (title) onAddSection(title);
    setNewSectionTitle("");
    setAddingSection(false);
  };
  const cancelSection = () => {
    setNewSectionTitle("");
    setAddingSection(false);
  };

  const draggingTask = draggingId
    ? columns.flatMap((c) => c.tasks).find((t) => t["@id"] === draggingId)
    : null;
  const draggingSection =
    draggingId && draggingId.startsWith(SECTION_PREFIX)
      ? columns.find((c) => `${SECTION_PREFIX}${c.key}` === draggingId)
      : null;

  const handleStart = (event: DragStartEvent) =>
    setDraggingId(String(event.active.id));

  const handleEnd = (event: DragEndEvent) => {
    setDraggingId(null);
    const { active, over } = event;
    if (!over) return;
    const activeId = String(active.id);
    const overId = String(over.id);
    // `over` can be a column's card-drop area (`col:`) or, when reordering, a
    // column's sortable node (`section:`) — resolve either to a column key.
    const overKey = overId.startsWith(COL_PREFIX)
      ? overId.slice(COL_PREFIX.length)
      : overId.startsWith(SECTION_PREFIX)
        ? overId.slice(SECTION_PREFIX.length)
        : null;
    if (overKey === null) return;
    const target = columns.find((c) => c.key === overKey);
    if (!target) return;

    // Column reorder: a section header was dragged onto another column.
    if (activeId.startsWith(SECTION_PREFIX)) {
      const activeKey = activeId.slice(SECTION_PREFIX.length);
      if (activeKey !== target.key) onReorderSections(activeKey, target.key);
      return;
    }

    // Otherwise a task card was dropped on a column → move it there.
    const task = columns.flatMap((c) => c.tasks).find((t) => t["@id"] === activeId);
    if (task && task.section !== target.sectionIri) {
      onMove(activeId, target.sectionIri);
    }
  };

  const sectionIds = columns.map((c) => `${SECTION_PREFIX}${c.key}`);

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={boardCollision}
      onDragStart={handleStart}
      onDragEnd={handleEnd}
    >
      {/* px/pt give focus rings room — overflow-x-auto also clips vertically,
          so a ring on the section-name input or a card would be cut off.
          items-stretch + a viewport min-height make every column (and the
          add-section placeholder) the same, full height. */}
      <div
        className="flex min-h-[calc(100vh-16rem)] items-stretch gap-4 overflow-x-auto px-0.5 pt-1 pb-2"
        data-testid="task-board"
      >
        <SortableContext items={sectionIds} strategy={horizontalListSortingStrategy}>
          {columns.map((column) => (
            <BoardColumnView
              key={column.key}
              column={column}
              definitions={definitions}
              assignableUsers={assignableUsers}
              onOpen={onOpen}
              onAssign={onAssign}
              onAddTask={onAddTask}
              onDeleteSection={onDeleteSection}
            />
          ))}
        </SortableContext>
        {/* The add-section placeholder is itself a full-height column (matches
            the real columns via the parent's items-stretch) with its control
            pinned to the top. */}
        <div className="flex w-80 shrink-0 flex-col rounded-xl border border-dashed p-2">
          {addingSection ? (
            <input
              autoFocus
              value={newSectionTitle}
              onChange={(e) => setNewSectionTitle(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  commitSection();
                } else if (e.key === "Escape") {
                  cancelSection();
                }
              }}
              onBlur={cancelSection}
              placeholder="Section name…"
              className="h-9 w-full rounded-lg border bg-card px-3 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              data-testid="board-add-section-input"
            />
          ) : (
            <button
              type="button"
              onClick={() => setAddingSection(true)}
              className="flex items-center gap-1 rounded-md px-1.5 py-1 text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
              data-testid="board-add-section"
            >
              <Plus className="h-4 w-4" /> Add section
            </button>
          )}
        </div>
      </div>
      <DragOverlay>
        {draggingTask ? (
          <div className="w-80 rounded-lg border bg-card p-3.5 shadow-lg">
            <CardBody
              task={draggingTask}
              definitions={definitions}
              assignableUsers={assignableUsers}
              onAssign={onAssign}
            />
          </div>
        ) : draggingSection ? (
          <div className="flex w-80 items-center gap-2 rounded-xl border bg-card px-3 py-2 shadow-lg">
            <GripVertical className="h-4 w-4 text-muted-foreground" />
            <span className="text-base font-bold tracking-tight text-foreground">
              {draggingSection.title}
            </span>
            <span className="rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
              {draggingSection.tasks.length}
            </span>
          </div>
        ) : null}
      </DragOverlay>
    </DndContext>
  );
};

export default TaskBoard;
