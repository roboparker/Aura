import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { CalendarRange, CornerDownRight } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { valuePairDefinitionIri } from "@/components/tasks/CustomFieldValueList";
import { analyseSchedule, edgeKey, nestByParent } from "@/lib/timelineAnalysis";

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
  /** 'required' → dependency arrow; 'parent' → row nesting. */
  type: string;
}

interface BoardTimelineProps<T extends TimelineTask> {
  /**
   * Where dependency edges come from. A board id keeps the chart scoped to one
   * board; a space id unions every board in the space (the `/timeline` page).
   */
  boardId?: string;
  spaceId?: string;
  tasks: T[];
  /**
   * Row groups. Boards pass their sections; the space-level view passes one
   * group per board. Grouping is just labelled buckets either way, so the same
   * chart serves both.
   */
  sections: Section[];
  /** Which field on a task decides its group. Defaults to `section`. */
  groupBy?: (task: T) => string | null;
  /**
   * Disable rescheduling. The cross-board view is for seeing how work lines up,
   * and the start-field config lives on the owning board — so drags belong
   * there, not here.
   */
  readOnly?: boolean;
  /** Whether the feature is on. Drives the empty state — independent of the
   *  field having loaded, so flipping the toggle clears the empty state at once. */
  enabled: boolean;
  /**
   * The canonical Start-date field IRI, or null while it hasn't loaded yet.
   * When enabled but null, bars fall back to milestones (dueDate only) until
   * the field arrives.
   */
  startFieldIri: string | null;
  /**
   * Whether this viewer may switch Timeline on. Mirrors the server's
   * `space.boards.update` check — offering a button that 403s is worse than
   * not offering one.
   */
  canEnable?: boolean;
  /** Turn Timeline on from here. Omitted on the cross-board view. */
  onEnable?: () => void | Promise<void>;
  /** True while the enable request is in flight, so the button can say so. */
  enabling?: boolean;
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
  spaceId,
  tasks,
  sections,
  groupBy,
  readOnly = false,
  enabled,
  startFieldIri,
  canEnable = false,
  onEnable,
  enabling = false,
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

  // Dependency + parent edges. Board-scoped, or space-scoped for /timeline.
  const edgesUrl = spaceId
    ? `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/dependencies`
    : boardId
      ? `${ENTRYPOINT}/boards/${encodeURIComponent(boardId)}/dependencies`
      : null;

  useEffect(() => {
    if (!edgesUrl) return;
    let live = true;
    void fetch(edgesUrl, {
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
  }, [edgesUrl, tasks]);

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

  // Rows grouped by section (board order), then nested so subtasks sit under
  // their parent within the section. Scheduled rows only.
  const groups = useMemo(() => {
    const orderedSections = [
      ...sections.map((s) => ({ key: s["@id"], title: s.title })),
      { key: "__none__", title: sections.length > 0 ? "No section" : "Tasks" },
    ];
    return orderedSections
      .map((g) => {
        const keyOf = groupBy ?? ((t: T) => t.section);
        const inSection = scheduled.filter((r) =>
          g.key === "__none__" ? keyOf(r.task) === null : keyOf(r.task) === g.key,
        );
        // nestByParent keys on `id`, so hand it a shim and read the row back.
        const nested = nestByParent(
          inSection.map((r) => ({ id: r.task.id, row: r })),
          edges,
        );
        return { ...g, rows: nested.map((n) => ({ row: n.item.row, depth: n.depth })) };
      })
      .filter((g) => g.rows.length > 0);
  }, [sections, scheduled, edges, groupBy]);

  // Flatten to positioned rows so bar y-coordinates (and arrow geometry) line up.
  const positioned = useMemo(() => {
    const out: { row: Row<T>; depth: number; y: number }[] = [];
    let y = 0;
    for (const g of groups) {
      y += ROW_H; // section header row
      for (const r of g.rows) {
        out.push({ row: r.row, depth: r.depth, y });
        y += ROW_H;
      }
    }
    return { list: out, height: y };
  }, [groups]);

  // Read-only schedule analysis: which bars are on the critical path, and which
  // dependency arrows are violated. Never edits anything (see timelineAnalysis).
  const analysis = useMemo(
    () =>
      analyseSchedule(
        positioned.list
          .filter(({ row }) => row.endDay !== null)
          .map(({ row }) => ({
            id: row.task.id,
            startDay: row.startDay ?? (row.endDay as number),
            endDay: row.endDay as number,
          })),
        edges,
      ),
    [positioned, edges],
  );

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
    // Read-only charts let the click fall through to "open task" instead.
    if (readOnly) return;
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
    // Turn it on from here when the viewer is allowed to. Sending someone to
    // Settings for a one-field toggle is a pointless detour, and telling
    // someone who *can't* change it to go there is worse than pointless.
    const offerButton = canEnable === true && typeof onEnable === "function";

    return (
      <div className="flex flex-col items-center justify-center gap-2 rounded-md border border-dashed py-16 text-center">
        <CalendarRange className="h-8 w-8 text-muted-foreground" aria-hidden />
        <p className="font-medium">Timeline isn&apos;t turned on</p>
        <p className="max-w-sm text-sm text-muted-foreground">
          {offerButton
            ? "Plan this board's tasks on a Gantt chart. Each bar runs from its Start date to its due date."
            : "Someone who can manage this board needs to turn Timeline on before it can be used here."}
        </p>

        {offerButton && (
          <>
            <Button
              className="mt-2"
              data-testid="timeline-enable-button"
              disabled={enabling}
              onClick={() => void onEnable?.()}
            >
              {enabling ? "Turning on…" : "Turn on Timeline"}
            </Button>
            {/* Says what else the switch does before it's flipped, rather than
                leaving the new field to be discovered afterwards. */}
            <p className="max-w-sm text-xs text-muted-foreground">
              This adds the shared <strong>Start date</strong> field to the
              board. It can&apos;t be removed while Timeline is on.
            </p>
          </>
        )}
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
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
          <span>
            {scheduled.length} scheduled
            {unscheduled.length > 0 && ` · ${unscheduled.length} unscheduled`}
          </span>
          {/* Only explain the analysis colours when they're actually on screen. */}
          {analysis.criticalTaskIds.size > 0 && (
            <span className="inline-flex items-center gap-1 text-xs">
              <span className="size-2 rounded-sm bg-amber-500" aria-hidden />
              Critical path
            </span>
          )}
          {analysis.violatedEdgeKeys.size > 0 && (
            <span
              className="inline-flex items-center gap-1 text-xs text-rose-600 dark:text-rose-400"
              data-testid="timeline-violation-note"
            >
              <span className="size-2 rounded-sm bg-rose-500" aria-hidden />
              {analysis.violatedEdgeKeys.size === 1
                ? "1 dependency issue"
                : `${analysis.violatedEdgeKeys.size} dependency issues`}
            </span>
          )}
        </div>
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
                  {g.rows.map(({ row: r, depth }) => (
                    <button
                      key={r.task.id}
                      type="button"
                      onClick={() => onOpenTask(r.task)}
                      style={{
                        height: ROW_H,
                        // Indent subtasks under their parent (12px per level).
                        paddingLeft: 12 + depth * 12,
                      }}
                      className="flex w-full items-center pr-3 text-left text-sm hover:bg-accent/50"
                      data-testid={depth > 0 ? "timeline-row-nested" : "timeline-row"}
                    >
                      {depth > 0 && (
                        <CornerDownRight
                          className="mr-1 size-3 shrink-0 text-muted-foreground/60"
                          aria-hidden
                        />
                      )}
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
                  <marker id="tl-arrow-bad" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto">
                    <path d="M0,0 L6,3 L0,6 Z" className="fill-rose-500" />
                  </marker>
                </defs>
                {/* Only `required` edges are dependencies; `parent` edges are
                    expressed by row nesting instead of arrows. */}
                {edges
                  .filter((edge) => edge.type === "required")
                  .map((edge, i) => {
                    const from = barGeom.get(edge.source);
                    const to = barGeom.get(edge.target);
                    if (!from || !to) return null;
                    const violated = analysis.violatedEdgeKeys.has(edgeKey(edge));
                    const x1 = from.x + from.w;
                    const y1 = from.y + ROW_H / 2;
                    const x2 = to.x;
                    const y2 = to.y + ROW_H / 2;
                    const midX = Math.max(x1 + 8, x2 - 8);
                    return (
                      <path
                        key={i}
                        d={`M${x1},${y1} H${midX} V${y2} H${x2}`}
                        className={cn(
                          violated ? "stroke-rose-500" : "stroke-muted-foreground/60",
                        )}
                        strokeWidth={violated ? 2 : 1.5}
                        fill="none"
                        markerEnd={violated ? "url(#tl-arrow-bad)" : "url(#tl-arrow)"}
                        data-testid={violated ? "timeline-violated-edge" : "timeline-edge"}
                      >
                        <title>
                          {violated
                            ? "This task starts before the task it depends on finishes."
                            : "Depends on the task this arrow comes from."}
                        </title>
                      </path>
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
                const critical = analysis.criticalTaskIds.has(row.task.id);

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
                      done ? "bg-emerald-500/80" : critical ? "bg-amber-500" : "bg-sky-500",
                      // Critical-path bars get a ring as well as a hue so the
                      // signal survives for colour-blind viewers.
                      critical && !done && "ring-2 ring-amber-300",
                      isDragging && "ring-2 ring-sky-300",
                    )}
                    data-testid={critical ? "timeline-bar-critical" : "timeline-bar"}
                    style={{
                      left: geom.x,
                      top: y + 6,
                      width: Math.max(dayWidth, geom.w),
                      height: ROW_H - 12,
                      transform: `translateX(${shift}px)`,
                    }}
                    title={
                      critical
                        ? `${row.task.title} — on the critical path`
                        : row.task.title
                    }
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
