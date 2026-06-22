import { useState } from "react";
import {
  DndContext,
  DragOverlay,
  PointerSensor,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
  type DragEndEvent,
  type DragStartEvent,
} from "@dnd-kit/core";
import { MoreHorizontal, Plus, Trash2 } from "lucide-react";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
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
export interface BoardTask {
  "@id": string;
  id: string;
  title: string;
  completedOn: string | null;
  dueDate: string | null;
  section: string | null;
  tags: { "@id": string; title: string }[];
  assignees: AvatarUser[];
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
  onOpen: (taskIri: string) => void;
  onMove: (taskIri: string, sectionIri: string | null) => void;
  onAddTask: (sectionIri: string | null) => void;
  onAddSection: () => void;
  onDeleteSection: (sectionIri: string) => void;
}

const COL_PREFIX = "col:";

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
          className="inline-flex items-center gap-1 rounded border bg-muted/40 px-1.5 py-0.5 text-[10px]"
          data-testid="board-card-field"
        >
          <span className="text-muted-foreground">{def.name}</span>
          <CustomFieldValueCell
            definition={def}
            value={value}
            className="text-[10px] font-medium"
          />
        </span>
      ))}
    </div>
  );
};

const CardBody = ({
  task,
  definitions,
}: {
  task: BoardTask;
  definitions: CustomFieldDefinition[];
}) => (
  <div className="space-y-1.5">
    <p
      className={cn(
        "text-sm leading-snug",
        task.completedOn && "text-muted-foreground line-through",
      )}
    >
      {task.title}
    </p>
    <CardCustomFields task={task} definitions={definitions} />
    {(task.tags.length > 0 || task.dueDate || task.assignees.length > 0) && (
      <div className="flex flex-wrap items-center gap-1">
        {task.dueDate && (
          <Badge variant="secondary" className="px-1.5 py-0 text-[10px] font-normal">
            {dueLabel(task.dueDate)}
          </Badge>
        )}
        {task.tags.map((tag) => (
          <Badge
            key={tag["@id"]}
            variant="outline"
            className="px-1.5 py-0 text-[10px] font-normal"
          >
            {tag.title}
          </Badge>
        ))}
        {task.assignees.length > 0 && (
          <span className="ml-auto flex -space-x-1.5">
            {task.assignees.slice(0, 3).map((a, i) => (
              <UserAvatar key={i} user={a} size="sm" className="ring-2 ring-card" />
            ))}
          </span>
        )}
      </div>
    )}
  </div>
);

const TaskCard = ({
  task,
  definitions,
  onOpen,
}: {
  task: BoardTask;
  definitions: CustomFieldDefinition[];
  onOpen: (taskIri: string) => void;
}) => {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: task["@id"],
  });
  return (
    <button
      ref={setNodeRef}
      type="button"
      {...attributes}
      {...listeners}
      onClick={() => onOpen(task["@id"])}
      className={cn(
        "w-full cursor-grab rounded-lg border bg-card p-2.5 text-left shadow-sm transition hover:border-foreground/20 active:cursor-grabbing",
        isDragging && "opacity-40",
      )}
      data-testid="board-card"
    >
      <CardBody task={task} definitions={definitions} />
    </button>
  );
};

const BoardColumnView = ({
  column,
  definitions,
  onOpen,
  onAddTask,
  onDeleteSection,
}: {
  column: BoardColumn;
  definitions: CustomFieldDefinition[];
  onOpen: (taskIri: string) => void;
  onAddTask: (sectionIri: string | null) => void;
  onDeleteSection: (sectionIri: string) => void;
}) => {
  const { setNodeRef, isOver } = useDroppable({ id: `${COL_PREFIX}${column.key}` });
  return (
    <div
      className="flex w-72 shrink-0 flex-col rounded-xl bg-muted/40 p-2"
      data-testid="board-column"
    >
      <div className="mb-2 flex items-center gap-2 px-1">
        <span className="text-sm font-semibold">{column.title}</span>
        <span className="text-xs text-muted-foreground">{column.tasks.length}</span>
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
          "flex min-h-24 flex-1 flex-col gap-2 rounded-lg p-1 transition",
          isOver && "bg-foreground/5 ring-1 ring-inset ring-foreground/15",
        )}
      >
        {column.tasks.map((task) => (
          <TaskCard
            key={task["@id"]}
            task={task}
            definitions={definitions}
            onOpen={onOpen}
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
  onOpen,
  onMove,
  onAddTask,
  onAddSection,
  onDeleteSection,
}: TaskBoardProps) => {
  const [draggingId, setDraggingId] = useState<string | null>(null);
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
  );

  const draggingTask = draggingId
    ? columns.flatMap((c) => c.tasks).find((t) => t["@id"] === draggingId)
    : null;

  const handleStart = (event: DragStartEvent) =>
    setDraggingId(String(event.active.id));

  const handleEnd = (event: DragEndEvent) => {
    setDraggingId(null);
    const { active, over } = event;
    if (!over) return;
    const overId = String(over.id);
    if (!overId.startsWith(COL_PREFIX)) return;
    const colKey = overId.slice(COL_PREFIX.length);
    const target = columns.find((c) => c.key === colKey);
    if (!target) return;
    const taskIri = String(active.id);
    const task = columns.flatMap((c) => c.tasks).find((t) => t["@id"] === taskIri);
    if (task && task.section !== target.sectionIri) {
      onMove(taskIri, target.sectionIri);
    }
  };

  return (
    <DndContext sensors={sensors} onDragStart={handleStart} onDragEnd={handleEnd}>
      <div className="flex gap-4 overflow-x-auto pb-2" data-testid="task-board">
        {columns.map((column) => (
          <BoardColumnView
            key={column.key}
            column={column}
            definitions={definitions}
            onOpen={onOpen}
            onAddTask={onAddTask}
            onDeleteSection={onDeleteSection}
          />
        ))}
        <button
          type="button"
          onClick={onAddSection}
          className="flex h-9 w-72 shrink-0 items-center gap-1 rounded-lg border border-dashed px-3 text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
          data-testid="board-add-section"
        >
          <Plus className="h-4 w-4" /> Add section
        </button>
      </div>
      <DragOverlay>
        {draggingTask ? (
          <div className="w-72 rounded-lg border bg-card p-2.5 shadow-lg">
            <CardBody task={draggingTask} definitions={definitions} />
          </div>
        ) : null}
      </DragOverlay>
    </DndContext>
  );
};

export default TaskBoard;
