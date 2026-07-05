import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Clock, Play, Square } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGet, apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  BillingProjectOption,
  TimeEntry,
  elapsedSeconds,
  formatClock,
  formatDuration,
  isRunning,
} from "@/lib/timeEntryTypes";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";

const MERGE_PATCH = "application/merge-patch+json";

const SELECT_CLASS =
  "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50";

/** "YYYY-MM-DD" for a date input (local). */
const todayInput = (d: Date): string => {
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

const TimePage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [showComposer, setShowComposer] = useState(false);
  const [description, setDescription] = useState("");
  const [workDate, setWorkDate] = useState(() => todayInput(new Date()));
  const [startTime, setStartTime] = useState(() => timeInput(new Date()));
  const [endTime, setEndTime] = useState("");
  const [billable, setBillable] = useState(true);
  const [projectIri, setProjectIri] = useState("");
  const [categoryIri, setCategoryIri] = useState("");
  const [actionError, setActionError] = useState<string | null>(null);
  const [nowTick, setNowTick] = useState(() => Date.now());

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceIri = activeSpace?.["@id"] ?? null;
  const spaceId = activeSpace?.id ?? null;

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
  const running = entries.find(isRunning) ?? null;
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["time_entries"] });

  // Billing projects + categories the member may pick from.
  const projectsQuery = useQuery({
    queryKey: ["billing_project_options", spaceId],
    enabled: isAuthenticated && !!spaceId,
    queryFn: () =>
      apiGet<{ options: BillingProjectOption[] }>(`/spaces/${spaceId}/billing-project-options`, {
        errorMessage: "Failed to load billing projects.",
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

  // Default the pickers once loaded / when the project changes.
  useEffect(() => {
    if (!projectIri && projects.length > 0) setProjectIri(projects[0]["@id"]);
  }, [projects, projectIri]);
  useEffect(() => {
    if (categories.length > 0 && !categories.some((c) => c["@id"] === categoryIri)) {
      setCategoryIri(categories[0]["@id"]);
    }
  }, [categories, categoryIri]);

  useEffect(() => {
    if (!running) return;
    const t = setInterval(() => setNowTick(Date.now()), 1000);
    return () => clearInterval(t);
  }, [running]);

  const projectCategoryBody = () => ({
    billingProject: projectIri,
    category: categoryIri,
    ...(spaceIri ? { space: spaceIri } : {}),
  });

  const startTimer = useMutation({
    mutationFn: () =>
      apiSend<TimeEntry>("POST", "/time_entries", {
        errorMessage: "Failed to start the timer.",
        body: { startedAt: new Date().toISOString(), billable: true, ...projectCategoryBody() },
      }),
    onSuccess: () => {
      setActionError(null);
      void refresh();
    },
    onError: (e) => setActionError(e instanceof Error ? e.message : "Failed to start the timer."),
  });

  const stopTimer = useMutation({
    mutationFn: (entry: TimeEntry) =>
      apiSend<TimeEntry>("PATCH", entry["@id"], {
        contentType: MERGE_PATCH,
        errorMessage: "Failed to stop the timer.",
        body: { endedAt: new Date().toISOString() },
      }),
    onSuccess: () => void refresh(),
    onError: (e) => setActionError(e instanceof Error ? e.message : "Failed to stop the timer."),
  });

  const logEntry = useMutation({
    mutationFn: () =>
      apiSend<TimeEntry>("POST", "/time_entries", {
        errorMessage: "Failed to log time.",
        body: {
          description: description.trim() || null,
          startedAt: composeIso(workDate, startTime),
          endedAt: endTime ? composeIso(workDate, endTime) : null,
          billable,
          ...projectCategoryBody(),
        },
      }),
    onSuccess: () => {
      setDescription("");
      setEndTime("");
      setStartTime(timeInput(new Date()));
      setWorkDate(todayInput(new Date()));
      setShowComposer(false);
      setActionError(null);
      void refresh();
    },
    onError: (e) => setActionError(e instanceof Error ? e.message : "Failed to log time."),
  });

  const handleLog = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    logEntry.mutate();
  };

  const grouped = useMemo(() => {
    const byDay = new Map<string, TimeEntry[]>();
    for (const entry of entries) {
      const key = dayLabel(entry.startedAt);
      const list = byDay.get(key) ?? [];
      list.push(entry);
      byDay.set(key, list);
    }
    return Array.from(byDay.entries());
  }, [entries]);

  const canCreate = can("time_entries", "create");
  const canTrack = !!projectIri && !!categoryIri;
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
        <Label htmlFor="te-project">Billing project</Label>
        <select
          id="te-project"
          value={projectIri}
          onChange={(e) => setProjectIri(e.target.value)}
          disabled={noProjects}
          className={SELECT_CLASS}
        >
          {noProjects ? (
            <option value="">No billing projects</option>
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
        <Label htmlFor="te-category">Category</Label>
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
        <div className="mx-auto max-w-4xl">
          <PageHeader
            title="Time"
            icon={<Clock className="size-6 text-orange-600 dark:text-orange-400" />}
            count={entriesQuery.isLoading ? undefined : entries.length}
            actions={
              canCreate ? (
                <Button variant="outline" size="sm" onClick={() => setShowComposer((v) => !v)}>
                  {showComposer ? "Cancel" : "Log time"}
                </Button>
              ) : undefined
            }
          />

          {canCreate && noProjects && (
            <Alert className="mb-4">
              <AlertDescription>
                You need a billing project to track time.{" "}
                <Link href="/billing-projects" className="underline">
                  Set one up
                </Link>{" "}
                (or ask a space admin).
              </AlertDescription>
            </Alert>
          )}

          {canCreate && !noProjects && (
            <Card className="mb-6">
              <CardContent className="space-y-4 py-4">
                {projectCategoryPickers}
                <div className="flex items-center justify-between gap-4">
                  {running ? (
                    <>
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">
                          {running.description?.trim() || "Running timer"}
                        </p>
                        <p className="font-mono text-2xl tabular-nums">
                          {formatClock(elapsedSeconds(running, nowTick))}
                        </p>
                      </div>
                      <Button
                        variant="destructive"
                        onClick={() => stopTimer.mutate(running)}
                        disabled={stopTimer.isPending}
                      >
                        <Square className="size-4" /> Stop
                      </Button>
                    </>
                  ) : (
                    <>
                      <p className="text-sm text-muted-foreground">
                        Start a timer, or log past time.
                      </p>
                      <Button
                        onClick={() => startTimer.mutate()}
                        disabled={startTimer.isPending || !canTrack}
                      >
                        <Play className="size-4" /> Start timer
                      </Button>
                    </>
                  )}
                </div>
              </CardContent>
            </Card>
          )}

          {showComposer && !noProjects && (
            <Card className="mb-6">
              <CardContent className="py-6">
                <form onSubmit={handleLog} className="space-y-4">
                  {projectCategoryPickers}
                  <div className="space-y-1.5">
                    <Label htmlFor="te-desc">
                      Description{" "}
                      <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Input
                      id="te-desc"
                      value={description}
                      onChange={(e) => setDescription(e.target.value)}
                      maxLength={1000}
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
                        <span className="font-normal text-muted-foreground">(blank = running)</span>
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
                  <div className="flex items-center gap-2">
                    <Switch id="te-billable" checked={billable} onCheckedChange={setBillable} />
                    <Label htmlFor="te-billable">Billable</Label>
                  </div>
                  <Button type="submit" disabled={logEntry.isPending || !canTrack}>
                    {logEntry.isPending ? "Logging…" : "Log time"}
                  </Button>
                </form>
              </CardContent>
            </Card>
          )}

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {entriesQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : entries.length === 0 ? (
            <Card>
              <CardContent className="py-10 text-center text-muted-foreground">
                No time logged yet. Start a timer or log past time.
              </CardContent>
            </Card>
          ) : (
            <div className="space-y-6">
              {grouped.map(([day, dayEntries]) => (
                <section key={day}>
                  <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {day}
                  </h2>
                  <ul className="divide-y rounded-md border">
                    {dayEntries.map((entry) => (
                      <li key={entry["@id"]} className="flex items-center justify-between gap-4 px-4 py-3">
                        <div className="min-w-0">
                          <p className="truncate text-sm">
                            {entry.description?.trim() || (
                              <span className="text-muted-foreground">No description</span>
                            )}
                          </p>
                          <p className="text-xs text-muted-foreground">
                            {new Date(entry.startedAt).toLocaleTimeString(undefined, {
                              hour: "numeric",
                              minute: "2-digit",
                            })}
                            {entry.billingProject && projectName.has(entry.billingProject) && (
                              <> · {projectName.get(entry.billingProject)}</>
                            )}
                          </p>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                          {!entry.billable && <Badge variant="secondary">Non-billable</Badge>}
                          {entry.billedAt && <Badge variant="secondary">Invoiced</Badge>}
                          <span className="font-mono text-sm tabular-nums">
                            {isRunning(entry)
                              ? formatClock(elapsedSeconds(entry, nowTick))
                              : formatDuration(entry.durationSeconds ?? 0)}
                          </span>
                        </div>
                      </li>
                    ))}
                  </ul>
                </section>
              ))}
            </div>
          )}
        </div>
      </div>
    </>
  );
};

export default TimePage;
