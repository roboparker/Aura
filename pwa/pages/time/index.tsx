import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { Fragment, FormEvent, useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CalendarRange, Clock, List, Pencil, Play, Plus, Square, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGet, apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  ProjectOption,
  TimeEntry,
  elapsedSeconds,
  formatClock,
  formatDuration,
  isRunning,
} from "@/lib/timeEntryTypes";
import PageHeader from "@/components/common/PageHeader";
import WeekTimesheet from "@/components/time/WeekTimesheet";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const MERGE_PATCH = "application/merge-patch+json";

const SELECT_CLASS =
  "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50";

/** "YYYY-MM-DD" for a date input (local). */
const dateInput = (d: Date): string => {
  const pad = (n: number) => n.toString().padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};
/** "HH:mm" for a time input (local). */
const timeInput = (d: Date): string => {
  const pad = (n: number) => n.toString().padStart(2, "0");
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
/** Compose a local date + time into an absolute ISO string. */
const composeIso = (date: string, time: string): string =>
  new Date(`${date}T${time}`).toISOString();

const dayLabel = (iso: string): string =>
  new Date(iso).toLocaleDateString(undefined, {
    weekday: "short",
    month: "short",
    day: "numeric",
  });

const clockLabel = (iso: string): string =>
  new Date(iso).toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });

const TimePage = () => {
  const { isAuthenticated, isLoading: authLoading, user } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [view, setView] = useState<"list" | "week">("list");
  const [showComposer, setShowComposer] = useState(false);
  const [description, setDescription] = useState("");
  const [workDate, setWorkDate] = useState(() => dateInput(new Date()));
  const [startTime, setStartTime] = useState(() => timeInput(new Date()));
  const [endTime, setEndTime] = useState(() => timeInput(new Date()));
  const [projectIri, setProjectIri] = useState("");
  const [categoryIri, setCategoryIri] = useState("");
  const [editingIri, setEditingIri] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [nowTick, setNowTick] = useState(() => Date.now());

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceIri = activeSpace?.["@id"] ?? null;
  const spaceId = activeSpace?.id ?? null;
  const currentUserIri = user ? `/users/${user.id}` : null;

  const entriesQuery = useQuery({
    queryKey: ["time_entries", spaceIri],
    enabled: isAuthenticated,
    queryFn: () =>
      apiGetCollection<TimeEntry>(
        spaceIri ? `/time_entries?space=${encodeURIComponent(spaceIri)}` : "/time_entries",
        { errorMessage: "Failed to load time entries." },
      ),
  });
  const entries = useMemo(() => entriesQuery.data ?? [], [entriesQuery.data]);
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["time_entries"] });

  // Projects + categories the member may pick from.
  const projectsQuery = useQuery({
    queryKey: ["billing_project_options", spaceId],
    enabled: isAuthenticated && !!spaceId,
    queryFn: () =>
      apiGet<{ options: ProjectOption[] }>(`/spaces/${spaceId}/project-options`, {
        errorMessage: "Failed to load projects.",
      }).then((r) => r.options ?? []),
  });
  const projects = useMemo(() => projectsQuery.data ?? [], [projectsQuery.data]);
  const noProjects = !projectsQuery.isLoading && projects.length === 0;

  const selectedProject = projects.find((p) => p["@id"] === projectIri) ?? null;
  const categories = useMemo(() => selectedProject?.categories ?? [], [selectedProject]);
  const projectName = useMemo(() => {
    const m = new Map<string, string>();
    for (const p of projects) m.set(p["@id"], p.name);
    return m;
  }, [projects]);
  const categoryName = useMemo(() => {
    const m = new Map<string, string>();
    for (const p of projects) for (const c of p.categories) m.set(c["@id"], c.name);
    return m;
  }, [projects]);

  // Default the pickers once loaded / when the project changes.
  useEffect(() => {
    if (!projectIri && projects.length > 0) setProjectIri(projects[0]["@id"]);
  }, [projects, projectIri]);
  useEffect(() => {
    if (categories.length > 0 && !categories.some((c) => c["@id"] === categoryIri)) {
      setCategoryIri(categories[0]["@id"]);
    }
  }, [categories, categoryIri]);

  // The (at most one) running timer, pinned above the day groups.
  const running = useMemo(() => entries.find(isRunning) ?? null, [entries]);
  const runningIri = running?.["@id"] ?? null;
  useEffect(() => {
    if (!runningIri) return;
    const t = setInterval(() => setNowTick(Date.now()), 1000);
    return () => clearInterval(t);
  }, [runningIri]);

  const projectCategoryBody = () => ({
    project: projectIri,
    category: categoryIri,
    ...(spaceIri ? { space: spaceIri } : {}),
  });

  const logEntry = useMutation({
    mutationFn: () =>
      apiSend<TimeEntry>("POST", "/time_entries", {
        errorMessage: "Failed to log time.",
        body: {
          description: description.trim() || null,
          startedAt: composeIso(workDate, startTime),
          endedAt: endTime ? composeIso(workDate, endTime) : null,
          ...projectCategoryBody(),
        },
      }),
    onSuccess: () => {
      setDescription("");
      setStartTime(timeInput(new Date()));
      setEndTime(timeInput(new Date()));
      setWorkDate(dateInput(new Date()));
      setShowComposer(false);
      setActionError(null);
      void refresh();
    },
    onError: (e) => setActionError(e instanceof Error ? e.message : "Failed to log time."),
  });

  const startTimer = useMutation({
    mutationFn: () =>
      apiSend<TimeEntry>("POST", "/time_entries", {
        errorMessage: "Failed to start the timer.",
        body: { startedAt: new Date().toISOString(), ...projectCategoryBody() },
      }),
    onSuccess: () => {
      setActionError(null);
      void refresh();
    },
    onError: (e) =>
      setActionError(e instanceof Error ? e.message : "Failed to start the timer."),
  });

  const stopTimer = useMutation({
    mutationFn: (entry: TimeEntry) => {
      // Entries can't span midnight — a timer left running overnight is
      // capped at the end of its start day (matching the server rule).
      const start = new Date(entry.startedAt);
      let end = new Date();
      if (end.toDateString() !== start.toDateString()) {
        end = new Date(start);
        end.setHours(23, 59, 59, 0);
      }
      return apiSend<TimeEntry>("PATCH", entry["@id"], {
        contentType: MERGE_PATCH,
        errorMessage: "Failed to stop the timer.",
        body: { endedAt: end.toISOString() },
      });
    },
    onSuccess: () => {
      setActionError(null);
      void refresh();
    },
    onError: (e) =>
      setActionError(e instanceof Error ? e.message : "Failed to stop the timer."),
  });

  const updateEntry = useMutation({
    mutationFn: ({ iri, body }: { iri: string; body: Record<string, unknown> }) =>
      apiSend<TimeEntry>("PATCH", iri, {
        contentType: MERGE_PATCH,
        errorMessage: "Failed to update the entry.",
        body,
      }),
    onSuccess: () => {
      setEditingIri(null);
      setActionError(null);
      void refresh();
    },
    onError: (e) =>
      setActionError(e instanceof Error ? e.message : "Failed to update the entry."),
  });

  const deleteEntry = useMutation({
    mutationFn: (iri: string) =>
      apiSend("DELETE", iri, { errorMessage: "Failed to delete the entry." }),
    onSuccess: () => {
      setActionError(null);
      void refresh();
    },
    onError: (e) =>
      setActionError(e instanceof Error ? e.message : "Failed to delete the entry."),
  });

  const handleLog = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    logEntry.mutate();
  };

  const handleDelete = (entry: TimeEntry) => {
    if (!window.confirm("Delete this time entry?")) return;
    deleteEntry.mutate(entry["@id"]);
  };

  // Completed entries only — the running timer renders as its own pinned row.
  const grouped = useMemo(() => {
    const byDay = new Map<string, TimeEntry[]>();
    for (const entry of entries) {
      if (isRunning(entry)) continue;
      const key = dayLabel(entry.startedAt);
      const list = byDay.get(key) ?? [];
      list.push(entry);
      byDay.set(key, list);
    }
    return Array.from(byDay.entries());
  }, [entries]);
  const hasCompleted = grouped.length > 0;

  const canCreate = can("time_entries", "create");
  const canTrack = !!projectIri && !!categoryIri;
  const isOwn = (entry: TimeEntry) =>
    !!currentUserIri && entry.user === currentUserIri;
  const canModify = (entry: TimeEntry) =>
    !entry.billedAt && (isOwn(entry) || can("time_entries", "update"));
  const error = actionError || (entriesQuery.isError ? "Failed to load time entries." : null);

  if (authLoading || !isAuthenticated) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  const projectCategoryPickers = (
    <div className="grid gap-3 sm:grid-cols-2">
      <div className="space-y-1.5">
        <Label htmlFor="te-project">Project</Label>
        <select
          id="te-project"
          value={projectIri}
          onChange={(e) => setProjectIri(e.target.value)}
          disabled={noProjects}
          className={SELECT_CLASS}
        >
          {noProjects ? (
            <option value="">No projects</option>
          ) : (
            projects.map((p) => (
              <option key={p["@id"]} value={p["@id"]}>
                {p.name}
              </option>
            ))
          )}
        </select>
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="te-category">Service</Label>
        <select
          id="te-category"
          value={categoryIri}
          onChange={(e) => setCategoryIri(e.target.value)}
          disabled={categories.length === 0}
          className={SELECT_CLASS}
        >
          {categories.length === 0 ? (
            <option value="">No categories</option>
          ) : (
            categories.map((c) => (
              <option key={c["@id"]} value={c["@id"]}>
                {c.name}
              </option>
            ))
          )}
        </select>
      </div>
    </div>
  );

  return (
    <>
      <Head>
        <title>Time — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-5xl">
          <PageHeader
            title="Time"
            icon={<Clock className="size-6 text-orange-600 dark:text-orange-400" />}
          />

          {canCreate && noProjects && (
            <Alert className="mb-4">
              <AlertDescription>
                You need a project to track time.{" "}
                <Link href="/projects" className="underline">
                  Set one up
                </Link>{" "}
                (or ask a space admin).
              </AlertDescription>
            </Alert>
          )}

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <div className="mb-3 inline-flex rounded-md border p-0.5" role="tablist">
            <Button
              variant={view === "list" ? "secondary" : "ghost"}
              size="sm"
              role="tab"
              aria-selected={view === "list"}
              onClick={() => setView("list")}
            >
              <List className="h-4 w-4" /> List
            </Button>
            <Button
              variant={view === "week" ? "secondary" : "ghost"}
              size="sm"
              role="tab"
              aria-selected={view === "week"}
              onClick={() => setView("week")}
            >
              <CalendarRange className="h-4 w-4" /> Week
            </Button>
          </div>

          {view === "week" ? (
            <WeekTimesheet
              spaceIri={spaceIri}
              projects={projects}
              canCreate={canCreate && !noProjects}
              canModify={canModify}
              onError={setActionError}
            />
          ) : entriesQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <Card>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2 font-medium">Description</th>
                      <th className="px-4 py-2 font-medium">Project</th>
                      <th className="px-4 py-2 font-medium">Service</th>
                      <th className="px-4 py-2 font-medium">Time</th>
                      <th className="px-4 py-2 font-medium">Billable</th>
                      <th className="px-4 py-2 text-right font-medium">Duration</th>
                      <th className="px-2 py-2" aria-label="Actions" />
                    </tr>
                  </thead>
                  <tbody>
                    {/* Inline "Add time" row, pinned to the top of the table. */}
                    {canCreate && !noProjects &&
                      (showComposer ? (
                        <tr className="border-b bg-muted/10">
                          <td colSpan={7} className="px-4 py-4">
                            <form onSubmit={handleLog} className="space-y-4">
                              {projectCategoryPickers}
                              <div className="space-y-1.5">
                                <Label htmlFor="te-desc">
                                  Description{" "}
                                  <span className="font-normal text-muted-foreground">
                                    (optional)
                                  </span>
                                </Label>
                                <Textarea
                                  id="te-desc"
                                  value={description}
                                  onChange={(e) => setDescription(e.target.value)}
                                  maxLength={1000}
                                  rows={3}
                                  placeholder="What did you work on?"
                                />
                              </div>
                              <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                  <Label htmlFor="te-date">Date</Label>
                                  <Input
                                    id="te-date"
                                    type="date"
                                    value={workDate}
                                    onChange={(e) => setWorkDate(e.target.value)}
                                    required
                                  />
                                </div>
                                <div className="space-y-1.5">
                                  <Label htmlFor="te-start">Start</Label>
                                  <Input
                                    id="te-start"
                                    type="time"
                                    value={startTime}
                                    onChange={(e) => setStartTime(e.target.value)}
                                    required
                                  />
                                </div>
                                <div className="space-y-1.5">
                                  <Label htmlFor="te-end">
                                    End{" "}
                                    <span className="font-normal text-muted-foreground">
                                      (blank = running)
                                    </span>
                                  </Label>
                                  <Input
                                    id="te-end"
                                    type="time"
                                    value={endTime}
                                    onChange={(e) => setEndTime(e.target.value)}
                                  />
                                </div>
                              </div>
                              <p className="text-xs text-muted-foreground">
                                An entry can&apos;t span midnight — log separate entries per day.
                              </p>
                              <div className="flex items-center justify-end gap-2">
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => setShowComposer(false)}
                                >
                                  Cancel
                                </Button>
                                <Button
                                  type="submit"
                                  size="sm"
                                  disabled={logEntry.isPending || !canTrack}
                                >
                                  {logEntry.isPending ? "Logging…" : "Log time"}
                                </Button>
                              </div>
                            </form>
                          </td>
                        </tr>
                      ) : (
                        <tr className="border-b">
                          <td colSpan={7} className="px-4 py-2">
                            <div className="flex items-center gap-4">
                              <button
                                type="button"
                                onClick={() => setShowComposer(true)}
                                className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                              >
                                <Plus className="h-4 w-4" /> Add time
                              </button>
                              {!running && (
                                <button
                                  type="button"
                                  onClick={() => startTimer.mutate()}
                                  disabled={!canTrack || startTimer.isPending}
                                  title={
                                    canTrack
                                      ? `Start a timer on ${projectName.get(projectIri) ?? "…"} · ${categoryName.get(categoryIri) ?? "…"} (change via Add time)`
                                      : "Pick an project first"
                                  }
                                  className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                  <Play className="h-4 w-4" /> Start timer
                                </button>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))}

                    {/* Running timer, pinned above the day groups. */}
                    {running && (
                      <tr className="border-b bg-emerald-500/5" data-testid="running-entry">
                        <td className="px-4 py-2.5">
                          <span className="inline-flex items-center gap-2">
                            <span className="relative flex h-2 w-2 shrink-0">
                              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60" />
                              <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                            </span>
                            {running.description?.trim() || (
                              <span className="text-muted-foreground">Running timer</span>
                            )}
                          </span>
                        </td>
                        <td className="px-4 py-2.5 text-muted-foreground">
                          {running.project
                            ? projectName.get(running.project) ?? "—"
                            : "—"}
                        </td>
                        <td className="px-4 py-2.5 text-muted-foreground">
                          {running.category ? categoryName.get(running.category) ?? "—" : "—"}
                        </td>
                        <td className="whitespace-nowrap px-4 py-2.5 text-muted-foreground">
                          {clockLabel(running.startedAt)} · running
                        </td>
                        <td className="px-4 py-2.5" />
                        <td className="whitespace-nowrap px-4 py-2.5 text-right font-mono tabular-nums">
                          {formatClock(elapsedSeconds(running, nowTick))}
                        </td>
                        <td className="px-2 py-2.5 text-right">
                          <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => stopTimer.mutate(running)}
                            disabled={stopTimer.isPending}
                          >
                            <Square className="h-3.5 w-3.5" /> Stop
                          </Button>
                        </td>
                      </tr>
                    )}

                    {!hasCompleted && !running ? (
                      <tr>
                        <td
                          colSpan={7}
                          className="px-4 py-10 text-center text-muted-foreground"
                        >
                          No time logged yet. Use “Add time” to log some.
                        </td>
                      </tr>
                    ) : (
                      grouped.map(([day, dayEntries]) => (
                        <Fragment key={day}>
                          <tr className="bg-muted/40">
                            <td
                              colSpan={7}
                              className="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                            >
                              {day}
                            </td>
                          </tr>
                          {dayEntries.map((entry) =>
                            editingIri === entry["@id"] ? (
                              <EditEntryRow
                                key={entry["@id"]}
                                entry={entry}
                                projects={projects}
                                pending={updateEntry.isPending}
                                onCancel={() => setEditingIri(null)}
                                onSave={(body) =>
                                  updateEntry.mutate({ iri: entry["@id"], body })
                                }
                              />
                            ) : (
                              <tr
                                key={entry["@id"]}
                                className="group border-b last:border-0 align-middle"
                              >
                                <td className="px-4 py-2.5">
                                  {entry.description?.trim() || (
                                    <span className="text-muted-foreground">No description</span>
                                  )}
                                </td>
                                <td className="px-4 py-2.5 text-muted-foreground">
                                  {entry.project
                                    ? projectName.get(entry.project) ?? "—"
                                    : "—"}
                                </td>
                                <td className="px-4 py-2.5 text-muted-foreground">
                                  {entry.category
                                    ? categoryName.get(entry.category) ?? "—"
                                    : "—"}
                                </td>
                                <td className="whitespace-nowrap px-4 py-2.5 text-muted-foreground">
                                  {clockLabel(entry.startedAt)}
                                  {entry.endedAt ? `–${clockLabel(entry.endedAt)}` : ""}
                                </td>
                                <td className="px-4 py-2.5">
                                  <div className="flex items-center gap-1.5">
                                    {!entry.billable && (
                                      <Badge variant="secondary">Non-billable</Badge>
                                    )}
                                    {entry.billedAt && (
                                      <Badge variant="secondary">Invoiced</Badge>
                                    )}
                                  </div>
                                </td>
                                <td className="whitespace-nowrap px-4 py-2.5 text-right font-mono tabular-nums">
                                  {formatDuration(entry.durationSeconds ?? elapsedSeconds(entry))}
                                </td>
                                <td className="whitespace-nowrap px-2 py-2.5 text-right">
                                  {canModify(entry) && (
                                    <span className="inline-flex items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
                                      <button
                                        type="button"
                                        onClick={() => setEditingIri(entry["@id"])}
                                        aria-label="Edit entry"
                                        className="rounded p-1 text-muted-foreground hover:text-foreground"
                                      >
                                        <Pencil className="h-3.5 w-3.5" />
                                      </button>
                                      <button
                                        type="button"
                                        onClick={() => handleDelete(entry)}
                                        aria-label="Delete entry"
                                        className="rounded p-1 text-muted-foreground hover:text-destructive"
                                      >
                                        <Trash2 className="h-3.5 w-3.5" />
                                      </button>
                                    </span>
                                  )}
                                </td>
                              </tr>
                            ),
                          )}
                        </Fragment>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </Card>
          )}
        </div>
      </div>
    </>
  );
};

/**
 * Inline editor for one completed entry — mirrors the add-row form but with
 * its own local state (keyed by the entry, so switching rows remounts it).
 * Billed entries never reach this (the actions are hidden + the server 422s).
 */
const EditEntryRow = ({
  entry,
  projects,
  pending,
  onSave,
  onCancel,
}: {
  entry: TimeEntry;
  projects: ProjectOption[];
  pending: boolean;
  onSave: (body: Record<string, unknown>) => void;
  onCancel: () => void;
}) => {
  const [eDescription, setEDescription] = useState(entry.description ?? "");
  const [eDate, setEDate] = useState(() => dateInput(new Date(entry.startedAt)));
  const [eStart, setEStart] = useState(() => timeInput(new Date(entry.startedAt)));
  const [eEnd, setEEnd] = useState(() =>
    entry.endedAt ? timeInput(new Date(entry.endedAt)) : "",
  );
  const [eProject, setEProject] = useState(entry.project ?? "");
  const [eCategory, setECategory] = useState(entry.category ?? "");

  const cats = projects.find((p) => p["@id"] === eProject)?.categories ?? [];

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    onSave({
      description: eDescription.trim() || null,
      startedAt: composeIso(eDate, eStart),
      endedAt: eEnd ? composeIso(eDate, eEnd) : null,
      project: eProject,
      category: eCategory,
    });
  };

  return (
    <tr className="border-b bg-muted/10">
      <td colSpan={7} className="px-4 py-4">
        <form onSubmit={submit} className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="tee-project">Project</Label>
              <select
                id="tee-project"
                value={eProject}
                onChange={(e) => {
                  const next = e.target.value;
                  setEProject(next);
                  const nextCats =
                    projects.find((p) => p["@id"] === next)?.categories ?? [];
                  setECategory(nextCats[0]?.["@id"] ?? "");
                }}
                className={SELECT_CLASS}
              >
                {projects.map((p) => (
                  <option key={p["@id"]} value={p["@id"]}>
                    {p.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="tee-category">Service</Label>
              <select
                id="tee-category"
                value={eCategory}
                onChange={(e) => setECategory(e.target.value)}
                className={SELECT_CLASS}
              >
                {cats.map((c) => (
                  <option key={c["@id"]} value={c["@id"]}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="tee-desc">
              Description <span className="font-normal text-muted-foreground">(optional)</span>
            </Label>
            <Textarea
              id="tee-desc"
              value={eDescription}
              onChange={(e) => setEDescription(e.target.value)}
              maxLength={1000}
              rows={3}
            />
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-1.5">
              <Label htmlFor="tee-date">Date</Label>
              <Input
                id="tee-date"
                type="date"
                value={eDate}
                onChange={(e) => setEDate(e.target.value)}
                required
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="tee-start">Start</Label>
              <Input
                id="tee-start"
                type="time"
                value={eStart}
                onChange={(e) => setEStart(e.target.value)}
                required
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="tee-end">End</Label>
              <Input
                id="tee-end"
                type="time"
                value={eEnd}
                onChange={(e) => setEEnd(e.target.value)}
              />
            </div>
          </div>
          <div className="flex items-center justify-end gap-2">
            <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
              Cancel
            </Button>
            <Button type="submit" size="sm" disabled={pending || !eProject || !eCategory}>
              {pending ? "Saving…" : "Save changes"}
            </Button>
          </div>
        </form>
      </td>
    </tr>
  );
};

export default TimePage;
