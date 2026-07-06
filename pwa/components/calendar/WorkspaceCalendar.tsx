import { useEffect, useMemo, useState } from "react";
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
import { ChevronLeft, ChevronRight, Repeat } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { type AvatarUser } from "@/components/user/UserAvatar";
import AssignMenu, { type AssignableUser } from "@/components/tasks/AssignMenu";
import { cn } from "@/lib/utils";
import {
  WEEKDAYS,
  addDays,
  dayKey,
  monthGridDays,
  parseDayKey,
  startOfWeek,
  weekDays,
} from "@/lib/calendarDates";

/** One calendar entry as served by `GET /calendar` (a single day slot). */
export interface CalendarEntry {
  taskId: string;
  "@id": string;
  title: string;
  /** `YYYY-MM-DD` — the day this entry sits on. */
  occurrenceDate: string;
  /** The task's anchor due date (ISO); the series' first instance. */
  dueDate: string | null;
  completedOn: string | null;
  /** True for a live recurring series (drag prompts this/all). */
  recurring: boolean;
  board: { "@id": string; id: string; title: string } | null;
  assignees: (AvatarUser & { "@id": string })[];
  tags: { "@id": string; id: string; title: string; color: string }[];
}

export type RescheduleScope = "single" | "series";

type View = "month" | "week" | "day";

interface WorkspaceCalendarProps {
  entries: CalendarEntry[];
  isLoading?: boolean;
  /** Fired whenever the visible day window changes (inclusive `YYYY-MM-DD`). */
  onRangeChange: (startKey: string, endKey: string) => void;
  /** Open the task detail drawer. */
  onOpen: (taskId: string) => void;
  /**
   * Move an entry to `targetKey`. `scope` is only meaningful for a recurring
   * entry: `series` shifts the whole series' anchor, `single` detaches just
   * this occurrence.
   */
  onReschedule: (
    entry: CalendarEntry,
    targetKey: string,
    scope: RescheduleScope,
  ) => void | Promise<void>;
  /** Everyone assignable (for the per-card assign menu). */
  assignableUsers: AssignableUser[];
  /** Set a task's assignees from a card's assign menu. */
  onAssign: (taskId: string, userIris: string[]) => void | Promise<void>;
}

const dragId = (entry: CalendarEntry): string =>
  `${entry.occurrenceDate}|${entry.taskId}`;

const EntryCard = ({
  entry,
  onOpen,
  assignableUsers = [],
  onAssign,
  overlay = false,
}: {
  entry: CalendarEntry;
  onOpen?: (taskId: string) => void;
  assignableUsers?: AssignableUser[];
  onAssign?: (taskId: string, userIris: string[]) => void | Promise<void>;
  overlay?: boolean;
}) => {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: dragId(entry),
    data: { entry },
    disabled: overlay,
  });
  return (
    <div
      ref={overlay ? undefined : setNodeRef}
      {...(overlay ? {} : attributes)}
      {...(overlay ? {} : listeners)}
      onClick={() => onOpen?.(entry.taskId)}
      onKeyDown={(e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          onOpen?.(entry.taskId);
        }
      }}
      className={cn(
        "w-full cursor-grab space-y-1 rounded-md border bg-card px-2 py-1.5 text-left text-xs shadow-sm transition hover:border-foreground/30 active:cursor-grabbing",
        entry.completedOn && "opacity-60",
        isDragging && "opacity-40",
        overlay && "shadow-lg",
      )}
      title={entry.title}
      data-testid="calendar-entry"
    >
      <div className="mb-3 flex items-center gap-1">
        {entry.recurring && (
          <Repeat className="h-3 w-3 shrink-0 text-muted-foreground" aria-label="Repeats" />
        )}
        <span className="min-w-0 flex-1 truncate font-medium">{entry.title}</span>
      </div>
      {/* Assignee row — shows avatars, or the dashed placeholder + assign
          picker when no one is assigned (matches the board/list). */}
      <AssignMenu
        assignees={entry.assignees}
        assignableUsers={assignableUsers}
        onAssign={(iris) => onAssign?.(entry.taskId, iris)}
        align="start"
      />
    </div>
  );
};

const DayCell = ({
  day,
  entries,
  inMonth,
  isToday,
  onOpen,
  assignableUsers,
  onAssign,
  tall,
}: {
  day: Date;
  entries: CalendarEntry[];
  inMonth: boolean;
  isToday: boolean;
  onOpen: (taskId: string) => void;
  assignableUsers: AssignableUser[];
  onAssign: (taskId: string, userIris: string[]) => void | Promise<void>;
  tall: boolean;
}) => {
  const key = dayKey(day);
  const { setNodeRef, isOver } = useDroppable({ id: key });
  return (
    <div
      ref={setNodeRef}
      className={cn(
        "flex flex-col border-b border-r p-1",
        tall ? "min-h-40" : "min-h-24",
        !inMonth && "bg-muted/20 text-muted-foreground",
        isOver && "bg-foreground/5 ring-1 ring-inset ring-foreground/15",
        // Today gets a green outline (matches the board calendar).
        isToday && "ring-2 ring-inset ring-emerald-500",
      )}
      data-testid="calendar-day"
    >
      <div className="mb-1 px-0.5">
        <span
          className={cn(
            "inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-xs",
            isToday && "font-semibold text-emerald-600 dark:text-emerald-400",
          )}
        >
          {day.getDate()}
        </span>
      </div>
      <div className="space-y-1">
        {entries.map((entry) => (
          <EntryCard
            key={dragId(entry)}
            entry={entry}
            onOpen={onOpen}
            assignableUsers={assignableUsers}
            onAssign={onAssign}
          />
        ))}
      </div>
    </div>
  );
};

const WorkspaceCalendar = ({
  entries,
  isLoading,
  onRangeChange,
  onOpen,
  onReschedule,
  assignableUsers,
  onAssign,
}: WorkspaceCalendarProps) => {
  const [view, setView] = useState<View>("month");
  const [anchorKey, setAnchorKey] = useState<string>(() => dayKey(new Date()));
  const anchor = useMemo(() => parseDayKey(anchorKey), [anchorKey]);
  const todayKey = dayKey(new Date());

  const [draggingId, setDraggingId] = useState<string | null>(null);
  // A recurring drag waits on the this/all choice before committing.
  const [pendingDrag, setPendingDrag] = useState<{
    entry: CalendarEntry;
    targetKey: string;
  } | null>(null);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
  );

  const days = useMemo(() => {
    if (view === "day") return [anchor];
    if (view === "week") return weekDays(anchor);
    return monthGridDays(anchor);
  }, [view, anchor]);

  // Tell the page which window to fetch whenever the visible range shifts.
  useEffect(() => {
    if (days.length === 0) return;
    onRangeChange(dayKey(days[0]), dayKey(days[days.length - 1]));
  }, [days, onRangeChange]);

  const byDay = useMemo(() => {
    const map = new Map<string, CalendarEntry[]>();
    for (const entry of entries) {
      const list = map.get(entry.occurrenceDate) ?? [];
      list.push(entry);
      map.set(entry.occurrenceDate, list);
    }
    return map;
  }, [entries]);

  const label = useMemo(() => {
    if (view === "day") {
      return anchor.toLocaleDateString(undefined, {
        weekday: "long",
        month: "long",
        day: "numeric",
        year: "numeric",
      });
    }
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
    const step = view === "day" ? 1 : view === "week" ? 7 : 30;
    setAnchorKey(dayKey(addDays(anchor, dir * step)));
  };

  const draggingEntry = draggingId
    ? entries.find((e) => dragId(e) === draggingId) ?? null
    : null;

  const handleDragEnd = (event: DragEndEvent) => {
    setDraggingId(null);
    const { active, over } = event;
    if (!over) return;
    const entry = (active.data.current as { entry?: CalendarEntry } | undefined)?.entry;
    if (!entry) return;
    const targetKey = String(over.id);
    if (targetKey === entry.occurrenceDate) return;
    if (entry.recurring) {
      setPendingDrag({ entry, targetKey });
      return;
    }
    void onReschedule(entry, targetKey, "series");
  };

  const commitPending = (scope: RescheduleScope) => {
    if (!pendingDrag) return;
    void onReschedule(pendingDrag.entry, pendingDrag.targetKey, scope);
    setPendingDrag(null);
  };

  const gridCols = view === "day" ? "grid-cols-1" : "grid-cols-7";
  const tall = view !== "month";

  return (
    <div className="flex min-h-[calc(100vh-16rem)] flex-col" data-testid="workspace-calendar">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-1">
          <Button type="button" variant="outline" size="icon" className="h-8 w-8" onClick={() => shift(-1)} aria-label="Previous">
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <Button type="button" variant="outline" size="sm" onClick={() => setAnchorKey(todayKey)}>
            Today
          </Button>
          <Button type="button" variant="outline" size="icon" className="h-8 w-8" onClick={() => shift(1)} aria-label="Next">
            <ChevronRight className="h-4 w-4" />
          </Button>
          <span className="ml-2 text-sm font-semibold">{label}</span>
          {isLoading && <span className="ml-1 text-xs text-muted-foreground">loading…</span>}
        </div>
        <div className="inline-flex overflow-hidden rounded-md border">
          {(["month", "week", "day"] as const).map((v) => (
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
              data-testid={`calendar-view-${v}`}
            >
              {v}
            </button>
          ))}
        </div>
      </div>

      <DndContext
        sensors={sensors}
        onDragStart={(e: DragStartEvent) => setDraggingId(String(e.active.id))}
        onDragEnd={handleDragEnd}
        onDragCancel={() => setDraggingId(null)}
      >
        {view !== "day" && (
          <div className={cn("grid border-l border-t", gridCols)}>
            {WEEKDAYS.map((d) => (
              <div
                key={d}
                className="border-b border-r bg-muted/40 px-2 py-1 text-xs font-medium text-muted-foreground"
              >
                {d}
              </div>
            ))}
          </div>
        )}
        <div className={cn("grid flex-1 auto-rows-fr border-l", gridCols, view === "day" && "border-t")}>
          {days.map((day) => {
            const key = dayKey(day);
            const inMonth = view !== "month" || day.getMonth() === anchor.getMonth();
            return (
              <DayCell
                key={key}
                day={day}
                entries={byDay.get(key) ?? []}
                inMonth={inMonth}
                isToday={key === todayKey}
                onOpen={onOpen}
                assignableUsers={assignableUsers}
                onAssign={onAssign}
                tall={tall}
              />
            );
          })}
        </div>

        <DragOverlay>
          {draggingEntry ? (
            <div className="w-56">
              <EntryCard entry={draggingEntry} overlay />
            </div>
          ) : null}
        </DragOverlay>
      </DndContext>

      {/* Recurring drag → this occurrence vs the whole series. */}
      <Dialog open={pendingDrag !== null} onOpenChange={(open) => !open && setPendingDrag(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Move recurring task</DialogTitle>
            <DialogDescription>
              &ldquo;{pendingDrag?.entry.title}&rdquo; repeats. Move just this
              occurrence, or shift the whole series?
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2 sm:justify-start">
            <Button type="button" onClick={() => commitPending("single")} data-testid="calendar-move-single">
              This occurrence
            </Button>
            <Button
              type="button"
              variant="outline"
              onClick={() => commitPending("series")}
              data-testid="calendar-move-series"
            >
              All occurrences
            </Button>
            <Button type="button" variant="ghost" onClick={() => setPendingDrag(null)}>
              Cancel
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
};

export default WorkspaceCalendar;
