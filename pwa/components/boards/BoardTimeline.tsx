import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { CalendarRange } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { cn } from "@/lib/utils";
import { valuePairDefinitionIri } from "@/components/tasks/CustomFieldValueList";

/**
 * Board Timeline (#timeline) — a Gantt view of the board's tasks.
 *
 * A task's bar runs from a board-configured **start** date custom field to its
 * native **dueDate** (the deadline the rest of the app uses). A task with a due
 * date but no start renders as a milestone diamond; a task with neither is
 * listed as unscheduled below the chart rather than silently dropped.
 *
 * `required` relationships draw finish-to-start dependency arrows. Bars drag to
 * reschedule: the body shifts both dates, the edges resize one. Writes go back
 * through the parent — dueDate via `onMoveDue`, the start field via
 * `onMoveStart` — so the timeline reuses the same persistence the rest of the
 * board already has.
 */

interface TimelineTask {
  "@id": string;
  id: string;
  title: string;
  dueDate: string | null;
  completedOn: string | null;
  section: string | null;
  customFieldValues: { definition?: string | null; globalDefinition?: string | null; value: unknown }[];
}

interface Section {
  "@id": string;
  id: string;
  title: string;
}

interface Edge {
  source: string;
  target: string;
}

interface BoardTimelineProps<T extends TimelineTask> {
  boardId: string;
  tasks: T[];
  sections: Section[];
  /** Whether the feature is on. Drives the empty state — independent of the
   *  field having loaded, so flipping the toggle clears the empty state at once. */
  enabled: boolean;
  /**
   * The canonical Start-date field IRI, or null while it hasn't loaded yet.
   * When enabled but null, bars fall back to milestones (dueDate only) until
   * the field arrives.
   */
  startFieldIri: string | null;
  onOpenTask: (task: T) => void;
  /** Persist a new due date (ISO) or clear it. */
  onMoveDue: (task: T, dueIso: string | null) => void;
  /** Persist a new start-field value (date string) or clear it. */
  onMoveStart: (task: T, dateStr: string | null) => void;
}

type Zoom = "day" | "week" | "month";
const DAY_WIDTH: Record<Zoom, number> = { day: 32, week: 12, month: 4 };
const ROW_H = 36;
const LABEL_W = 220;
const MS_DAY = 86_400_000;

// ---- date helpers (all in UTC-day space to dodge DST drift) ----
const toDay = (iso: string): number => Math.floor(Date.parse(iso) / MS_DAY);
const dayToDate = (day: number): Date => new Date(day * MS_DAY);
const todayDay = (): number => Math.floor(Date.now() / MS_DAY);

const fmtHeader = (day: number, zoom: Zoom): string => {
  const d = dayToDate(day);
  if (zoom === "month") {
    return d.toLocaleDateString(undefined, { month: "short", year: "2-digit", timeZone: "UTC" });
  }
  if (zoom === "week") {
    return d.toLocaleDateString(undefined, { month: "short", day: "numeric", timeZone: "UTC" });
  }
  return d.toLocaleDateString(undefined, { day: "numeric", timeZone: "UTC" });
};

/** The date string a value holds, or null. Dates are stored as "YYYY-MM-DD" or ISO. */
const asDateString = (value: unknown): string | null =>
  typeof value === "string" && value.trim() !== "" ? value : null;

/** Shift a stored date string by whole days, preserving its shape (date vs ISO). */
const shiftDateString = (value: string, deltaDays: number): string => {
  const hadTime = value.includes("T");
  const shifted = new Date(Date.parse(value) + deltaDays * MS_DAY);
  return hadTime ? shifted.toISOString() : shifted.toISOString().slice(0, 10);
};

const shiftIso = (iso: string, deltaDays: number): string =>
  new Date(Date.parse(iso) + deltaDays * MS_DAY).toISOString();

interface Row<T extends TimelineTask> {
  task: T;
  startDay: number | null;
  endDay: number | null;
}

interface DragState {
  taskId: string;
  mode: "move" | "start" | "end";
  originX: number;
  deltaDays: number;
}

const BoardTimeline = <T extends TimelineTask>({
  boardId,
  tasks,
  sections,
  enabled,
  startFieldIri,
  onOpenTask,
  onMoveDue,
  onMoveStart,
}: BoardTimelineProps<T>) => {
  const [zoom, setZoom] = useState<Zoom>("day");
  const [edges, setEdges] = useState<Edge[]>([]);
  const [drag, setDrag] = useState<DragState | null>(null);
  const dragRef = useRef<DragState | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const dayWidth = DAY_WIDTH[zoom];

  // Dependency edges for the arrows. Refetched when the board changes.
  useEffect(() => {
    let live = true;
    void fetch(`${ENTRYPOINT}/boards/${encodeURIComponent(boardId)}/dependencies`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((res) => (res.ok ? res.json() : { edges: [] }))
      .then((data) => {
        if (live) setEdges(Array.isArray(data.edges) ? data.edges : []);
      })
      .catch(() => {});
    return () => {
      live = false;
    };
  }, [boardId, tasks]);

  // Resolve each task's [start, end] in day-space.
  const rows = useMemo<Row<T>[]>(() => {
    return tasks.map((task) => {
      const endDay = task.dueDate ? toDay(task.dueDate) : null;
      let startDay: number | null = null;
      if (startFieldIri) {
        const pair = task.customFieldValues.find(
          (v) => valuePairDefinitionIri(v) === startFieldIri,
        );
        const s = pair ? asDateString(pair.value) : null;
        if (s) startDay = toDay(s);
      }
      // A start after the end is nonsense data — clamp the bar to the end day.
      if (startDay !== null && endDay !== null && startDay > endDay) startDay = endDay;
      return { task, startDay, endDay };
    });
  }, [tasks, startFieldIri]);

  const scheduled = rows.filter((r) => r.endDay !== null);
  const unscheduled = rows.filter((r) => r.endDay === null);

  // The visible date domain: min start … max end, padded a few days each side.
  const domain = useMemo(() => {
    const days: number[] = [];
    for (const r of scheduled) {
      if (r.startDay !== null) days.push(r.startDay);
      if (r.endDay !== null) days.push(r.endDay);
    }
    const t = todayDay();
    days.push(t);
    const min = Math.min(...days) - 3;
    const max = Math.max(...days) + 3;
    return { min, max, span: Math.max(1, max - min + 1) };
  }, [scheduled]);

  const xForDay = useCallback(
    (day: number): number => (day - domain.min) * dayWidth,
    [domain.min, dayWidth],
  );

  // Rows grouped by section, keeping the board's section order; scheduled only.
  const groups = useMemo(() => {
    const orderedSections = [
      ...sections.map((s) => ({ key: s["@id"], title: s.title })),
      { key: "__none__", title: sections.length > 0 ? "No section" : "Tasks" },
    ];
    return orderedSections
      .map((g) => ({
        ...g,
        rows: scheduled.filter((r) =>
          g.key === "__none__" ? r.task.section === null : r.task.section === g.key,
        ),
      }))
      .filter((g) => g.rows.length > 0);
  }, [sections, scheduled]);

  // Flatten to positioned rows so bar y-coordinates (and arrow geometry) line up.
  const positioned = useMemo(() => {
    const out: { row: Row<T>; y: number }[] = [];
    let y = 0;
    for (const g of groups) {
      y += ROW_H; // section header row
      for (const r of g.rows) {
        out.push({ row: r, y });
        y += ROW_H;
      }
    }
    return { list: out, height: y };
  }, [groups]);

  const barGeom = useMemo(() => {
    const map = new Map<string, { x: number; w: number; y: number; milestone: boolean }>();
    for (const { row, y } of positioned.list) {
      if (row.endDay === null) continue;
      const milestone = row.startDay === null;
      const startDay = row.startDay ?? row.endDay;
      const x = xForDay(startDay);
      const w = milestone ? ROW_H : (row.endDay - startDay + 1) * dayWidth;
      map.set(row.task.id, { x, w, y, milestone });
    }
    return map;
  }, [positioned, xForDay, dayWidth]);

  // ---- drag ----
  const onPointerDown = (
    e: React.PointerEvent,
    task: TimelineTask,
    mode: DragState["mode"],
  ) => {
    e.preventDefault();
    e.stopPropagation();
    const next: DragState = { taskId: task.id, mode, originX: e.clientX, deltaDays: 0 };
    dragRef.current = next;
    setDrag(next);
    (e.target as Element).setPointerCapture?.(e.pointerId);
  };

  useEffect(() => {
    if (!drag) return;
    const onMove = (e: PointerEvent) => {
      const d = dragRef.current;
      if (!d) return;
      const deltaDays = Math.round((e.clientX - d.originX) / dayWidth);
      if (deltaDays !== d.deltaDays) {
        const updated = { ...d, deltaDays };
        dragRef.current = updated;
        setDrag(updated);
      }
    };
    const onUp = () => {
      const d = dragRef.current;
      dragRef.current = null;
      setDrag(null);
      if (!d || d.deltaDays === 0) return;
      const row = rows.find((r) => r.task.id === d.taskId);
      if (!row) return;
      const startPair = startFieldIri
        ? row.task.customFieldValues.find((v) => valuePairDefinitionIri(v) === startFieldIri)
        : undefined;
      const startStr = startPair ? asDateString(startPair.value) : null;

      if ((d.mode === "move" || d.mode === "end") && row.task.dueDate) {
        onMoveDue(row.task, shiftIso(row.task.dueDate, d.deltaDays));
      }
      if ((d.mode === "move" || d.mode === "start") && startStr) {
        onMoveStart(row.task, shiftDateString(startStr, d.deltaDays));
      }
    };
    window.addEventListener("pointermove", onMove);
    window.addEventListener("pointerup", onUp);
    return () => {
      window.removeEventListener("pointermove", onMove);
      window.removeEventListener("pointerup", onUp);
    };
  }, [drag, dayWidth, rows, startFieldIri, onMoveDue, onMoveStart]);

  // Scroll so today is roughly in view on first paint.
  useLayoutEffect(() => {
    if (scrollRef.current) {
      scrollRef.current.scrollLeft = Math.max(0, xForDay(todayDay()) - 200);
    }
    // Only on mount / zoom change.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [zoom]);

  if (!enabled) {
    return (
      <div className="flex flex-col items-center justify-center gap-2 rounded-md border border-dashed py-16 text-center">
        <CalendarRange className="h-8 w-8 text-muted-foreground" aria-hidden />
        <p className="font-medium">Timeline isn&apos;t turned on</p>
        <p className="max-w-sm text-sm text-muted-foreground">
          Enable Timeline in the board&apos;s Settings to plan tasks on a Gantt
          chart. Each bar runs from its Start date to its due date.
        </p>
      </div>
    );
  }

  const chartWidth = domain.span * dayWidth;
  const today = todayDay();

  // Axis ticks: one per day/week/month depending on zoom.
  const step = zoom === "day" ? 1 : zoom === "week" ? 7 : 30;
  const ticks: number[] = [];
  for (let d = domain.min; d <= domain.max; d += step) ticks.push(d);

  return (
    <div className="space-y-3" data-testid="board-timeline">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          {scheduled.length} scheduled
          {unscheduled.length > 0 && ` · ${unscheduled.length} unscheduled`}
        </p>
        <div className="flex items-center gap-1 rounded-md border p-0.5" role="group" aria-label="Zoom">
          {(["day", "week", "month"] as Zoom[]).map((z) => (
            <button
              key={z}
              type="button"
              onClick={() => setZoom(z)}
              className={cn(
                "rounded px-2 py-1 text-xs capitalize",
                zoom === z ? "bg-accent font-medium" : "text-muted-foreground hover:text-foreground",
              )}
              data-testid={`timeline-zoom-${z}`}
            >
              {z}
            </button>
          ))}
        </div>
      </div>

      <div className="overflow-x-auto rounded-md border" ref={scrollRef}>
        <div style={{ width: LABEL_W + chartWidth, minWidth: "100%" }}>
          {/* axis */}
          <div className="sticky top-0 z-10 flex border-b bg-background">
            <div style={{ width: LABEL_W }} className="shrink-0 border-r px-3 py-2 text-xs font-medium text-muted-foreground">
              Task
            </div>
            <div className="relative" style={{ width: chartWidth, height: 32 }}>
              {ticks.map((d) => (
                <div
                  key={d}
                  className="absolute top-0 h-full border-l text-[10px] text-muted-foreground"
                  style={{ left: xForDay(d) }}
                >
                  <span className="pl-1">{fmtHeader(d, zoom)}</span>
                </div>
              ))}
            </div>
          </div>

          {/* body */}
          <div className="relative flex">
            {/* labels column */}
            <div style={{ width: LABEL_W }} className="shrink-0 border-r">
              {groups.map((g) => (
                <div key={g.key}>
                  <div
                    style={{ height: ROW_H }}
                    className="flex items-center bg-muted/40 px-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                  >
                    <span className="truncate">{g.title}</span>
                  </div>
                  {g.rows.map((r) => (
                    <button
                      key={r.task.id}
                      type="button"
                      onClick={() => onOpenTask(r.task)}
                      style={{ height: ROW_H }}
                      className="flex w-full items-center px-3 text-left text-sm hover:bg-accent/50"
                    >
                      <span
                        className={cn(
                          "truncate",
                          r.task.completedOn && "text-muted-foreground line-through",
                        )}
                      >
                        {r.task.title}
                      </span>
                    </button>
                  ))}
                </div>
              ))}
            </div>

            {/* chart column */}
            <div className="relative" style={{ width: chartWidth, height: positioned.height }}>
              {/* today line */}
              {today >= domain.min && today <= domain.max && (
                <div
                  className="pointer-events-none absolute top-0 z-0 w-px bg-rose-400/70"
                  style={{ left: xForDay(today) + dayWidth / 2, height: positioned.height }}
                  aria-hidden
                />
              )}

              {/* dependency arrows */}
              <svg
                className="pointer-events-none absolute inset-0 z-10"
                width={chartWidth}
                height={positioned.height}
              >
                <defs>
                  <marker id="tl-arrow" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto">
                    <path d="M0,0 L6,3 L0,6 Z" className="fill-muted-foreground" />
                  </marker>
                </defs>
                {edges.map((edge, i) => {
                  const from = barGeom.get(edge.source);
                  const to = barGeom.get(edge.target);
                  if (!from || !to) return null;
                  const x1 = from.x + from.w;
                  const y1 = from.y + ROW_H / 2;
                  const x2 = to.x;
                  const y2 = to.y + ROW_H / 2;
                  const midX = Math.max(x1 + 8, x2 - 8);
                  return (
                    <path
                      key={i}
                      d={`M${x1},${y1} H${midX} V${y2} H${x2}`}
                      className="stroke-muted-foreground/60"
                      strokeWidth={1.5}
                      fill="none"
                      markerEnd="url(#tl-arrow)"
                    />
                  );
                })}
              </svg>

              {/* bars */}
              {positioned.list.map(({ row, y }) => {
                const geom = barGeom.get(row.task.id);
                if (!geom) return null;
                const isDragging = drag?.taskId === row.task.id;
                const shift = isDragging ? drag.deltaDays * dayWidth : 0;
                const done = row.task.completedOn !== null;

                if (geom.milestone) {
                  return (
                    <div
                      key={row.task.id}
                      className="absolute z-20 flex items-center justify-center"
                      style={{ left: geom.x, top: y, width: ROW_H, height: ROW_H, transform: `translateX(${shift}px)` }}
                    >
                      <div
                        role="button"
                        tabIndex={0}
                        title={`${row.task.title} — due (milestone)`}
                        onPointerDown={(e) => onPointerDown(e, row.task, "move")}
                        onClick={() => onOpenTask(row.task)}
                        className={cn(
                          "h-3.5 w-3.5 rotate-45 cursor-grab rounded-[2px]",
                          done ? "bg-emerald-500" : "bg-amber-500",
                        )}
                        data-testid="timeline-milestone"
                      />
                    </div>
                  );
                }

                return (
                  <div
                    key={row.task.id}
                    className={cn(
                      "group absolute z-20 flex items-center rounded-md text-xs text-white shadow-sm",
                      done ? "bg-emerald-500/80" : "bg-sky-500",
                      isDragging && "ring-2 ring-sky-300",
                    )}
                    style={{
                      left: geom.x,
                      top: y + 6,
                      width: Math.max(dayWidth, geom.w),
                      height: ROW_H - 12,
                      transform: `translateX(${shift}px)`,
                    }}
                    title={row.task.title}
                    data-testid="timeline-bar"
                  >
                    {/* left resize handle */}
                    <span
                      onPointerDown={(e) => onPointerDown(e, row.task, "start")}
                      className="h-full w-1.5 cursor-ew-resize rounded-l-md opacity-0 group-hover:bg-black/20 group-hover:opacity-100"
                      aria-hidden
                    />
                    {/* body (move) */}
                    <span
                      onPointerDown={(e) => onPointerDown(e, row.task, "move")}
                      onClick={() => !drag && onOpenTask(row.task)}
                      className="flex-1 cursor-grab truncate px-1.5"
                    >
                      {row.task.title}
                    </span>
                    {/* right resize handle */}
                    <span
                      onPointerDown={(e) => onPointerDown(e, row.task, "end")}
                      className="h-full w-1.5 cursor-ew-resize rounded-r-md opacity-0 group-hover:bg-black/20 group-hover:opacity-100"
                      aria-hidden
                    />
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </div>

      {unscheduled.length > 0 && (
        <div className="rounded-md border border-dashed p-3">
          <p className="mb-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Unscheduled ({unscheduled.length}) — no due date
          </p>
          <div className="flex flex-wrap gap-1.5">
            {unscheduled.map((r) => (
              <button
                key={r.task.id}
                type="button"
                onClick={() => onOpenTask(r.task)}
                className="rounded border px-2 py-1 text-xs hover:bg-accent"
              >
                {r.task.title}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

export default BoardTimeline;
