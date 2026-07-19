import { useMemo, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight, CopyPlus, Lock, Plus, Send } from "lucide-react";
import { apiGet, apiGetCollection, apiSend } from "@/lib/apiClient";
import {
  ProjectOption,
  TimeEntry,
  formatCellDuration,
  parseDurationInput,
} from "@/lib/timeEntryTypes";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";

const MERGE_PATCH = "application/merge-patch+json";

const SELECT_CLASS =
  "h-8 rounded-md border border-input bg-transparent px-2 text-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring";

/** Monday 00:00 (local) of the week containing `d`. */
const mondayOf = (d: Date): Date => {
  const monday = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const dow = (monday.getDay() + 6) % 7; // Mon=0 … Sun=6
  monday.setDate(monday.getDate() - dow);
  return monday;
};

const addDays = (d: Date, days: number): Date => {
  const next = new Date(d);
  next.setDate(next.getDate() + days);
  return next;
};

/** "YYYY-MM-DD" in local time — the grid's day-bucket key. */
const localDateKey = (d: Date): string => {
  const pad = (n: number) => n.toString().padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const rowKeyOf = (project: string | null, category: string | null): string =>
  `${project ?? ""}|${category ?? ""}`;

interface CellData {
  entries: TimeEntry[];
  total: number;
}

/** One row from GET /spaces/{id}/timesheets (#654). */
interface TimesheetRow {
  id: string;
  weekStart: string | null;
  status: "pending" | "approved" | "rejected";
  note: string | null;
}

const SUBMISSION_BADGE: Record<TimesheetRow["status"], string> = {
  pending: "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300",
  approved: "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300",
  rejected: "bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300",
};

interface RowData {
  key: string;
  project: string | null;
  category: string | null;
  cells: Map<string, CellData>;
  total: number;
}

/**
 * Harvest-style week grid (#645): rows = project+category pairs with time
 * this week (plus any rows added via "Add row" / "Copy last week"), columns =
 * Mon–Sun, cells = editable durations. A cell edit creates/updates/deletes the
 * underlying entry: one duration-anchored entry per cell keeps the same-day
 * rule happy. Cells backed by multiple entries (or billed/foreign entries)
 * render read-only — the list view is the place to untangle those.
 */
const WeekTimesheet = ({
  spaceIri,
  projects,
  canCreate,
  canModify,
  onError,
}: {
  spaceIri: string | null;
  projects: ProjectOption[];
  canCreate: boolean;
  canModify: (entry: TimeEntry) => boolean;
  onError: (message: string | null) => void;
}) => {
  const queryClient = useQueryClient();
  const [weekStart, setWeekStart] = useState(() => mondayOf(new Date()));
  // Pairs surfaced without entries yet (added by hand or copied from last week).
  const [extraRows, setExtraRows] = useState<Set<string>>(new Set());
  const [draft, setDraft] = useState<{ cell: string; value: string } | null>(null);
  const [addingRow, setAddingRow] = useState(false);
  const [addProject, setAddProject] = useState("");
  const [addCategory, setAddCategory] = useState("");
  // Escape sets this so the blur it triggers discards instead of committing.
  const cancelEditRef = useRef(false);

  const weekKey = localDateKey(weekStart);
  const days = useMemo(
    () => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)),
    [weekStart],
  );
  const todayKey = localDateKey(new Date());

  const weekQueryPath = (start: Date): string => {
    const params = new URLSearchParams();
    if (spaceIri) params.set("space", spaceIri);
    // Widen by a day each side (the filter compares in UTC; buckets are local).
    params.set("startedAt[after]", localDateKey(addDays(start, -1)));
    params.set("startedAt[before]", localDateKey(addDays(start, 8)));
    params.set("itemsPerPage", "100");
    return `/time_entries?${params.toString()}`;
  };

  const entriesQuery = useQuery({
    queryKey: ["time_entries", "week", spaceIri, weekKey],
    queryFn: () =>
      apiGetCollection<TimeEntry>(weekQueryPath(weekStart), {
        errorMessage: "Failed to load the week.",
      }),
  });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["time_entries"] });

  // Timesheet approvals (#654): the viewer's own submission for this week.
  const spaceId = spaceIri ? spaceIri.slice(spaceIri.lastIndexOf("/") + 1) : null;
  const submissionQuery = useQuery({
    queryKey: ["timesheets", spaceId, weekKey],
    enabled: !!spaceId,
    queryFn: () =>
      apiGet<{ rows: TimesheetRow[] }>(`/spaces/${spaceId}/timesheets`, {
        errorMessage: "Failed to load the timesheet status.",
      }).then((r) => (r.rows ?? []).find((row) => row.weekStart === weekKey) ?? null),
  });
  const submission = submissionQuery.data ?? null;
  const weekLocked = !!submission && submission.status !== "rejected";

  const submitWeek = useMutation({
    mutationFn: () =>
      apiSend("POST", `/spaces/${spaceId}/timesheets/submit`, {
        contentType: "application/json",
        errorMessage: "Failed to submit the week.",
        body: { weekStart: weekKey },
      }),
    onSuccess: () => {
      onError(null);
      void queryClient.invalidateQueries({ queryKey: ["timesheets"] });
    },
    onError: (e) => onError(e instanceof Error ? e.message : "Failed to submit the week."),
  });

  // Completed entries bucketed by (pair, local day) inside the shown week.
  const rows = useMemo((): RowData[] => {
    const weekEnd = addDays(weekStart, 7);
    const byKey = new Map<string, RowData>();
    const ensureRow = (project: string | null, category: string | null): RowData => {
      const key = rowKeyOf(project, category);
      let row = byKey.get(key);
      if (!row) {
        row = { key, project, category, cells: new Map(), total: 0 };
        byKey.set(key, row);
      }
      return row;
    };

    for (const entry of entriesQuery.data ?? []) {
      if (entry.endedAt === null) continue; // running timer lives in list view
      const started = new Date(entry.startedAt);
      if (started < weekStart || started >= weekEnd) continue;
      const row = ensureRow(entry.project, entry.category);
      const dayKey = localDateKey(started);
      const cell = row.cells.get(dayKey) ?? { entries: [], total: 0 };
      cell.entries.push(entry);
      cell.total += entry.durationSeconds ?? 0;
      row.cells.set(dayKey, cell);
      row.total += entry.durationSeconds ?? 0;
    }
    for (const key of extraRows) {
      const [project, category] = key.split("|");
      ensureRow(project || null, category || null);
    }

    const nameOf = (row: RowData): string => {
      const p = projects.find((x) => x["@id"] === row.project);
      const c = p?.categories.find((x) => x["@id"] === row.category);
      return `${p?.name ?? "zzz"} ${c?.name ?? ""}`;
    };
    return Array.from(byKey.values()).sort((a, b) => nameOf(a).localeCompare(nameOf(b)));
  }, [entriesQuery.data, weekStart, extraRows, projects]);

  const dayTotals = useMemo(() => {
    const totals = new Map<string, number>();
    for (const row of rows) {
      for (const [dayKey, cell] of row.cells) {
        totals.set(dayKey, (totals.get(dayKey) ?? 0) + cell.total);
      }
    }
    return totals;
  }, [rows]);
  const grandTotal = rows.reduce((sum, row) => sum + row.total, 0);

  const labelFor = (row: RowData): { project: string; category: string } => {
    const p = projects.find((x) => x["@id"] === row.project);
    const c = p?.categories.find((x) => x["@id"] === row.category);
    return {
      project: p?.name ?? "Unknown project",
      category: c?.name ?? "—",
    };
  };

  const saveCell = useMutation({
    mutationFn: async ({
      row,
      day,
      cell,
      seconds,
    }: {
      row: RowData;
      day: Date;
      cell: CellData | undefined;
      seconds: number;
    }) => {
      const single = cell && cell.entries.length === 1 ? cell.entries[0] : null;
      if (single) {
        if (seconds === 0) {
          return apiSend("DELETE", single["@id"], {
            errorMessage: "Failed to delete the entry.",
          });
        }
        // Keep the entry's start time unless the new duration would cross
        // midnight — then re-anchor at the start of the day.
        let start = new Date(single.startedAt);
        const secondsIntoDay =
          start.getHours() * 3600 + start.getMinutes() * 60 + start.getSeconds();
        if (secondsIntoDay + seconds >= 24 * 3600) {
          start = new Date(day);
        }
        return apiSend("PATCH", single["@id"], {
          contentType: MERGE_PATCH,
          errorMessage: "Failed to update the entry.",
          body: {
            startedAt: start.toISOString(),
            endedAt: new Date(start.getTime() + seconds * 1000).toISOString(),
          },
        });
      }
      if (seconds === 0) return null;
      // New duration entry — noon-anchored so it reads naturally in the list
      // view, falling back to midnight when noon + duration would cross days.
      const noonFits = 12 * 3600 + seconds < 24 * 3600;
      const start = new Date(day.getTime() + (noonFits ? 12 * 3600 * 1000 : 0));
      return apiSend("POST", "/time_entries", {
        errorMessage: "Failed to log time.",
        body: {
          project: row.project,
          category: row.category,
          startedAt: start.toISOString(),
          endedAt: new Date(start.getTime() + seconds * 1000).toISOString(),
          ...(spaceIri ? { space: spaceIri } : {}),
        },
      });
    },
    onSuccess: () => {
      onError(null);
      void refresh();
    },
    onError: (e) => onError(e instanceof Error ? e.message : "Failed to save the cell."),
  });

  const commitCell = (row: RowData, day: Date, raw: string) => {
    setDraft(null);
    if (cancelEditRef.current) {
      cancelEditRef.current = false;
      return;
    }
    const cell = row.cells.get(localDateKey(day));
    const seconds = parseDurationInput(raw);
    if (seconds === null) {
      onError('Enter a duration like "1:30" or "1.5".');
      return;
    }
    if (seconds === (cell?.total ?? 0)) return;
    if (seconds > 0 && !cell?.entries.length && (!row.project || !row.category)) {
      onError("This row has no project/category — edit it in the list view.");
      return;
    }
    saveCell.mutate({ row, day, cell, seconds });
  };

  const copyLastWeek = useMutation({
    mutationFn: () =>
      apiGetCollection<TimeEntry>(weekQueryPath(addDays(weekStart, -7)), {
        errorMessage: "Failed to load last week.",
      }),
    onSuccess: (lastWeek) => {
      onError(null);
      const prevStart = addDays(weekStart, -7);
      const keys = new Set(extraRows);
      let added = 0;
      for (const entry of lastWeek ?? []) {
        if (entry.endedAt === null) continue;
        const started = new Date(entry.startedAt);
        if (started < prevStart || started >= weekStart) continue;
        if (!entry.project || !entry.category) continue;
        const key = rowKeyOf(entry.project, entry.category);
        if (!keys.has(key) && !rows.some((r) => r.key === key)) {
          keys.add(key);
          added += 1;
        }
      }
      setExtraRows(keys);
      if (added === 0) onError("Nothing new to copy from last week.");
    },
    onError: (e) => onError(e instanceof Error ? e.message : "Failed to load last week."),
  });

  const goToWeek = (start: Date) => {
    setWeekStart(start);
    setExtraRows(new Set());
    setDraft(null);
  };

  const addCats = projects.find((p) => p["@id"] === addProject)?.categories ?? [];
  const rangeLabel = `${weekStart.toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
  })} – ${addDays(weekStart, 6).toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
  })}`;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-1.5">
          <Button
            variant="outline"
            size="sm"
            onClick={() => goToWeek(addDays(weekStart, -7))}
            aria-label="Previous week"
          >
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => goToWeek(addDays(weekStart, 7))}
            aria-label="Next week"
          >
            <ChevronRight className="h-4 w-4" />
          </Button>
          <Button variant="outline" size="sm" onClick={() => goToWeek(mondayOf(new Date()))}>
            Today
          </Button>
          <span className="ml-2 text-sm font-medium">{rangeLabel}</span>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {submission && (
            <span
              className={cn(
                "rounded px-2 py-0.5 text-xs font-medium",
                SUBMISSION_BADGE[submission.status],
              )}
              title={submission.note ?? undefined}
            >
              {submission.status === "pending"
                ? "Submitted — pending approval"
                : submission.status === "approved"
                  ? "Approved"
                  : `Rejected${submission.note ? ` — ${submission.note}` : ""}`}
            </span>
          )}
          {canCreate && !weekLocked && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => copyLastWeek.mutate()}
              disabled={copyLastWeek.isPending}
            >
              <CopyPlus className="h-4 w-4" />
              {copyLastWeek.isPending ? "Copying…" : "Copy last week"}
            </Button>
          )}
          {canCreate && !weekLocked && (
            <Button
              size="sm"
              onClick={() => submitWeek.mutate()}
              disabled={submitWeek.isPending}
              title="Submit this week for approval — entries lock until it's reviewed"
            >
              <Send className="h-4 w-4" />
              {submitWeek.isPending
                ? "Submitting…"
                : submission?.status === "rejected"
                  ? "Re-submit week"
                  : "Submit week"}
            </Button>
          )}
        </div>
      </div>

      <Card>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                <th className="min-w-48 px-4 py-2 font-medium">Project / category</th>
                {days.map((day) => (
                  <th
                    key={localDateKey(day)}
                    className={cn(
                      "w-20 px-2 py-2 text-center font-medium",
                      localDateKey(day) === todayKey && "text-foreground",
                    )}
                  >
                    {day.toLocaleDateString(undefined, { weekday: "short" })}
                    <span className="block font-normal">
                      {day.toLocaleDateString(undefined, { day: "numeric" })}
                    </span>
                  </th>
                ))}
                <th className="w-20 px-3 py-2 text-right font-medium">Total</th>
              </tr>
            </thead>
            <tbody>
              {entriesQuery.isLoading ? (
                <tr>
                  <td colSpan={9} className="px-4 py-10 text-center text-muted-foreground">
                    Loading…
                  </td>
                </tr>
              ) : rows.length === 0 ? (
                <tr>
                  <td colSpan={9} className="px-4 py-10 text-center text-muted-foreground">
                    No time this week. Add a row below or copy last week&apos;s rows.
                  </td>
                </tr>
              ) : (
                rows.map((row) => {
                  const label = labelFor(row);
                  return (
                    <tr key={row.key} className="border-b align-middle">
                      <td className="px-4 py-2">
                        <span className="font-medium">{label.project}</span>
                        <span className="block text-xs text-muted-foreground">
                          {label.category}
                        </span>
                      </td>
                      {days.map((day) => {
                        const dayKey = localDateKey(day);
                        const cell = row.cells.get(dayKey);
                        const cellId = `${row.key}@${dayKey}`;
                        const editable =
                          canCreate &&
                          !weekLocked &&
                          (!cell ||
                            (cell.entries.length === 1 && canModify(cell.entries[0])));
                        const display =
                          draft?.cell === cellId
                            ? draft.value
                            : formatCellDuration(cell?.total ?? 0);
                        if (!editable) {
                          return (
                            <td
                              key={dayKey}
                              className="px-2 py-2 text-center tabular-nums text-muted-foreground"
                              title={
                                cell && cell.entries.length > 1
                                  ? "Multiple entries — edit in the list view"
                                  : cell?.entries.some((e) => !!e.billedAt)
                                    ? "Invoiced — locked"
                                    : undefined
                              }
                            >
                              <span className="inline-flex items-center gap-1">
                                {formatCellDuration(cell?.total ?? 0) || "—"}
                                {cell?.entries.some((e) => !!e.billedAt) && (
                                  <Lock className="h-3 w-3" />
                                )}
                              </span>
                            </td>
                          );
                        }
                        return (
                          <td key={dayKey} className="px-1 py-1.5 text-center">
                            <input
                              type="text"
                              inputMode="decimal"
                              value={display}
                              placeholder="0:00"
                              aria-label={`${label.project} ${label.category} on ${dayKey}`}
                              onFocus={() => setDraft({ cell: cellId, value: display })}
                              onChange={(e) =>
                                setDraft({ cell: cellId, value: e.target.value })
                              }
                              onBlur={(e) => commitCell(row, day, e.target.value)}
                              onKeyDown={(e) => {
                                if (e.key === "Enter") e.currentTarget.blur();
                                if (e.key === "Escape") {
                                  cancelEditRef.current = true;
                                  e.currentTarget.blur();
                                }
                              }}
                              className={cn(
                                "h-8 w-16 rounded-md border border-transparent bg-transparent text-center text-sm tabular-nums",
                                "hover:border-input focus-visible:border-input focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring",
                                dayKey === todayKey && "bg-muted/40",
                              )}
                            />
                          </td>
                        );
                      })}
                      <td className="px-3 py-2 text-right font-mono tabular-nums">
                        {formatCellDuration(row.total) || "—"}
                      </td>
                    </tr>
                  );
                })
              )}

              {/* Add-row control */}
              {canCreate && !weekLocked && (
                <tr className="border-b">
                  <td colSpan={9} className="px-4 py-2">
                    {addingRow ? (
                      <div className="flex flex-wrap items-center gap-2">
                        <select
                          value={addProject}
                          onChange={(e) => {
                            setAddProject(e.target.value);
                            const cats =
                              projects.find((p) => p["@id"] === e.target.value)
                                ?.categories ?? [];
                            setAddCategory(cats[0]?.["@id"] ?? "");
                          }}
                          className={SELECT_CLASS}
                          aria-label="Project"
                        >
                          <option value="">Project…</option>
                          {projects.map((p) => (
                            <option key={p["@id"]} value={p["@id"]}>
                              {p.name}
                            </option>
                          ))}
                        </select>
                        <select
                          value={addCategory}
                          onChange={(e) => setAddCategory(e.target.value)}
                          className={SELECT_CLASS}
                          disabled={addCats.length === 0}
                          aria-label="Category"
                        >
                          {addCats.length === 0 ? (
                            <option value="">Category…</option>
                          ) : (
                            addCats.map((c) => (
                              <option key={c["@id"]} value={c["@id"]}>
                                {c.name}
                              </option>
                            ))
                          )}
                        </select>
                        <Button
                          size="sm"
                          disabled={!addProject || !addCategory}
                          onClick={() => {
                            setExtraRows((prev) =>
                              new Set(prev).add(rowKeyOf(addProject, addCategory)),
                            );
                            setAddingRow(false);
                          }}
                        >
                          Add
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => setAddingRow(false)}>
                          Cancel
                        </Button>
                      </div>
                    ) : (
                      <button
                        type="button"
                        onClick={() => setAddingRow(true)}
                        className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                      >
                        <Plus className="h-4 w-4" /> Add row
                      </button>
                    )}
                  </td>
                </tr>
              )}
            </tbody>
            <tfoot>
              <tr className="bg-muted/40 text-sm font-medium">
                <td className="px-4 py-2">Total</td>
                {days.map((day) => (
                  <td
                    key={localDateKey(day)}
                    className="px-2 py-2 text-center font-mono tabular-nums"
                  >
                    {formatCellDuration(dayTotals.get(localDateKey(day)) ?? 0) || "—"}
                  </td>
                ))}
                <td className="px-3 py-2 text-right font-mono tabular-nums">
                  {formatCellDuration(grandTotal) || "—"}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </Card>
    </div>
  );
};

export default WeekTimesheet;
