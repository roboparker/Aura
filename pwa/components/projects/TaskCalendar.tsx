import { useMemo, useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import AssignMenu, { type AssignableUser } from "@/components/tasks/AssignMenu";
import CustomFieldValueCell from "@/components/custom-fields/CustomFieldValueCell";
import type { CustomFieldDefinition } from "@/components/custom-fields/types";
import type { CustomFieldValuePair } from "@/components/tasks/CustomFieldValueList";
import { isoToLocalDate } from "@/components/tasks/taskHelpers";
import { cn } from "@/lib/utils";

/** Minimal task shape the calendar needs. */
export interface CalendarTask {
  "@id": string;
  id: string;
  title: string;
  dueDate: string | null;
  completedOn: string | null;
  assignees: AssignableUser[];
  customFieldValues: CustomFieldValuePair[];
}

interface TaskCalendarProps {
  tasks: CalendarTask[];
  /** Custom fields to surface on calendar chips (filtered to calendar visibility). */
  definitions: CustomFieldDefinition[];
  /** Everyone assignable on this project, for the per-task assign menu. */
  assignableUsers: AssignableUser[];
  onOpen: (taskIri: string) => void;
  onAssign: (taskIri: string, userIris: string[]) => void | Promise<void>;
}

const isEmptyValue = (v: unknown): boolean =>
  v === null ||
  v === undefined ||
  v === "" ||
  (Array.isArray(v) && v.length === 0);

type View = "month" | "week";

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

/** Local YYYY-MM-DD key for a date (used to bucket tasks per day). */
const dayKey = (d: Date): string =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

const addDays = (d: Date, n: number): Date => {
  const next = new Date(d);
  next.setDate(next.getDate() + n);
  return next;
};

/** Sunday that starts the week containing `d`. */
const startOfWeek = (d: Date): Date => addDays(d, -d.getDay());

const TaskChip = ({
  task,
  definitions,
  assignableUsers,
  onOpen,
  onAssign,
}: {
  task: CalendarTask;
  definitions: CustomFieldDefinition[];
  assignableUsers: AssignableUser[];
  onOpen: (iri: string) => void;
  onAssign: (taskIri: string, userIris: string[]) => void | Promise<void>;
}) => {
  const fields = definitions
    .map((def) => ({
      def,
      value: task.customFieldValues.find((v) => v.definition === def["@id"])
        ?.value,
    }))
    .filter(({ value }) => !isEmptyValue(value));
  return (
    // A div (not a button) so the nested assign menu's button is valid markup
    // and its clicks don't bubble up to open the drawer.
    <div
      role="button"
      tabIndex={0}
      onClick={() => onOpen(task["@id"])}
      onKeyDown={(e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          onOpen(task["@id"]);
        }
      }}
      className={cn(
        "w-full cursor-pointer space-y-1 rounded-md border bg-card px-2 py-1.5 text-left text-xs hover:border-foreground/30",
        task.completedOn && "opacity-60",
      )}
      title={task.title}
      data-testid="calendar-task"
    >
      <div className="flex items-center gap-2">
        <span className={cn("min-w-0 flex-1 truncate", task.completedOn && "line-through")}>
          {task.title}
        </span>
        <AssignMenu
          assignees={task.assignees}
          assignableUsers={assignableUsers}
          onAssign={(iris) => onAssign(task["@id"], iris)}
        />
      </div>
      {fields.length > 0 && (
        <div className="flex flex-wrap gap-1">
          {fields.map(({ def, value }) => (
            <span
              key={def["@id"]}
              className="inline-flex items-center gap-1 rounded border bg-muted/40 px-1 py-0.5 text-xs"
            >
              <span className="text-muted-foreground">{def.name}</span>
              <CustomFieldValueCell
                definition={def}
                value={value}
                className="font-medium"
              />
            </span>
          ))}
        </div>
      )}
    </div>
  );
};

/**
 * Month / week calendar of a project's tasks, bucketed by due date with
 * assignee avatars. Clicking a task opens the detail drawer. Tasks with no
 * due date are surfaced in a small footer so they aren't lost.
 */
const TaskCalendar = ({
  tasks,
  definitions,
  assignableUsers,
  onOpen,
  onAssign,
}: TaskCalendarProps) => {
  const [view, setView] = useState<View>("month");
  // Anchor day — drives which month/week is shown. Local "today" at mount.
  const [anchorKey, setAnchorKey] = useState<string>(() => dayKey(new Date()));
  const anchor = useMemo(() => {
    const [y, m, d] = anchorKey.split("-").map(Number);
    return new Date(y, m - 1, d);
  }, [anchorKey]);

  const todayKey = dayKey(new Date());

  const { byDay, undated } = useMemo(() => {
    const map = new Map<string, CalendarTask[]>();
    const noDate: CalendarTask[] = [];
    for (const task of tasks) {
      const date = isoToLocalDate(task.dueDate);
      if (!date) {
        noDate.push(task);
        continue;
      }
      const key = dayKey(date);
      const list = map.get(key) ?? [];
      list.push(task);
      map.set(key, list);
    }
    return { byDay: map, undated: noDate };
  }, [tasks]);

  // The grid of days to render: 6 weeks for month, 1 week for week view.
  const days = useMemo(() => {
    if (view === "week") {
      const start = startOfWeek(anchor);
      return Array.from({ length: 7 }, (_, i) => addDays(start, i));
    }
    const firstOfMonth = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
    const gridStart = startOfWeek(firstOfMonth);
    return Array.from({ length: 42 }, (_, i) => addDays(gridStart, i));
  }, [view, anchor]);

  const label = useMemo(() => {
    if (view === "week") {
      const start = startOfWeek(anchor);
      const end = addDays(start, 6);
      const sameMonth = start.getMonth() === end.getMonth();
      const fmt = (d: Date, withMonth: boolean) =>
        d.toLocaleDateString(undefined, {
          month: withMonth ? "short" : undefined,
          day: "numeric",
        });
      return `${fmt(start, true)} – ${fmt(end, !sameMonth)}, ${end.getFullYear()}`;
    }
    return anchor.toLocaleDateString(undefined, { month: "long", year: "numeric" });
  }, [view, anchor]);

  const shift = (dir: -1 | 1) => {
    setAnchorKey(dayKey(addDays(anchor, dir * (view === "week" ? 7 : 30))));
  };

  return (
    <div data-testid="task-calendar">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-1">
          <Button
            type="button"
            variant="outline"
            size="icon"
            className="h-8 w-8"
            onClick={() => shift(-1)}
            aria-label="Previous"
          >
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setAnchorKey(todayKey)}
          >
            Today
          </Button>
          <Button
            type="button"
            variant="outline"
            size="icon"
            className="h-8 w-8"
            onClick={() => shift(1)}
            aria-label="Next"
          >
            <ChevronRight className="h-4 w-4" />
          </Button>
          <span className="ml-2 text-sm font-semibold">{label}</span>
        </div>
        <div className="inline-flex overflow-hidden rounded-md border">
          {(["month", "week"] as const).map((v) => (
            <button
              key={v}
              type="button"
              onClick={() => setView(v)}
              className={cn(
                "px-3 py-1 text-sm capitalize",
                view === v
                  ? "bg-primary text-primary-foreground"
                  : "text-muted-foreground hover:bg-accent",
              )}
            >
              {v}
            </button>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-7 border-l border-t">
        {WEEKDAYS.map((d) => (
          <div
            key={d}
            className="border-b border-r bg-muted/40 px-2 py-1 text-xs font-medium text-muted-foreground"
          >
            {d}
          </div>
        ))}
        {days.map((day) => {
          const key = dayKey(day);
          const dayTasks = byDay.get(key) ?? [];
          const inMonth = view === "week" || day.getMonth() === anchor.getMonth();
          const isToday = key === todayKey;
          const visible = view === "week" ? dayTasks : dayTasks.slice(0, 4);
          return (
            <div
              key={key}
              className={cn(
                "border-b border-r p-1 align-top",
                view === "week" ? "min-h-48" : "min-h-24",
                !inMonth && "bg-muted/20 text-muted-foreground",
              )}
            >
              <div className="mb-1 flex items-center justify-between px-0.5">
                <span
                  className={cn(
                    "inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-xs",
                    isToday && "bg-primary font-semibold text-primary-foreground",
                  )}
                >
                  {day.getDate()}
                </span>
              </div>
              <div className="space-y-1">
                {visible.map((task) => (
                  <TaskChip
                    key={task["@id"]}
                    task={task}
                    definitions={definitions}
                    assignableUsers={assignableUsers}
                    onOpen={onOpen}
                    onAssign={onAssign}
                  />
                ))}
                {view === "month" && dayTasks.length > visible.length && (
                  <span className="px-0.5 text-xs text-muted-foreground">
                    +{dayTasks.length - visible.length} more
                  </span>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {undated.length > 0 && (
        <div className="mt-3 rounded-md border p-2">
          <p className="mb-1 text-xs font-medium text-muted-foreground">
            No due date ({undated.length})
          </p>
          <div className="flex flex-wrap gap-1">
            {undated.map((task) => (
              <div key={task["@id"]} className="max-w-56">
                <TaskChip
                  task={task}
                  definitions={definitions}
                  assignableUsers={assignableUsers}
                  onOpen={onOpen}
                  onAssign={onAssign}
                />
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

export default TaskCalendar;
