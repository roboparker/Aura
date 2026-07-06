import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { Fragment, FormEvent, useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Clock, Plus } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGet, apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  EngagementOption,
  TimeEntry,
  elapsedSeconds,
  formatDuration,
} from "@/lib/timeEntryTypes";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

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
  const [endTime, setEndTime] = useState(() => timeInput(new Date()));
  const [projectIri, setProjectIri] = useState("");
  const [categoryIri, setCategoryIri] = useState("");
  const [actionError, setActionError] = useState<string | null>(null);

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
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["time_entries"] });

  // Engagements + categories the member may pick from.
  const projectsQuery = useQuery({
    queryKey: ["billing_project_options", spaceId],
    enabled: isAuthenticated && !!spaceId,
    queryFn: () =>
      apiGet<{ options: EngagementOption[] }>(`/spaces/${spaceId}/engagement-options`, {
        errorMessage: "Failed to load engagements.",
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

  const projectCategoryBody = () => ({
    engagement: projectIri,
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
        <Label htmlFor="te-project">Engagement</Label>
        <select
          id="te-project"
          value={projectIri}
          onChange={(e) => setProjectIri(e.target.value)}
          disabled={noProjects}
          className={SELECT_CLASS}
        >
          {noProjects ? (
            <option value="">No engagements</option>
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
          />

          {canCreate && noProjects && (
            <Alert className="mb-4">
              <AlertDescription>
                You need a engagement to track time.{" "}
                <Link href="/engagements" className="underline">
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

          {entriesQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <Card>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2 font-medium">Description</th>
                      <th className="px-4 py-2 font-medium">Engagement</th>
                      <th className="px-4 py-2 font-medium">Category</th>
                      <th className="px-4 py-2 font-medium">Time</th>
                      <th className="px-4 py-2 font-medium">Billable</th>
                      <th className="px-4 py-2 text-right font-medium">Duration</th>
                    </tr>
                  </thead>
                  <tbody>
                    {/* Inline "Add time" row, pinned to the top of the table. */}
                    {canCreate && !noProjects &&
                      (showComposer ? (
                        <tr className="border-b bg-muted/10">
                          <td colSpan={6} className="px-4 py-4">
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
                          <td colSpan={6} className="px-4 py-2">
                            <button
                              type="button"
                              onClick={() => setShowComposer(true)}
                              className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                            >
                              <Plus className="h-4 w-4" /> Add time
                            </button>
                          </td>
                        </tr>
                      ))}

                    {entries.length === 0 ? (
                      <tr>
                        <td
                          colSpan={6}
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
                              colSpan={6}
                              className="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                            >
                              {day}
                            </td>
                          </tr>
                          {dayEntries.map((entry) => {
                            const startT = new Date(entry.startedAt).toLocaleTimeString(undefined, {
                              hour: "numeric",
                              minute: "2-digit",
                            });
                            const endT = entry.endedAt
                              ? new Date(entry.endedAt).toLocaleTimeString(undefined, {
                                  hour: "numeric",
                                  minute: "2-digit",
                                })
                              : null;
                            return (
                              <tr key={entry["@id"]} className="border-b last:border-0 align-middle">
                                <td className="px-4 py-2.5">
                                  {entry.description?.trim() || (
                                    <span className="text-muted-foreground">No description</span>
                                  )}
                                </td>
                                <td className="px-4 py-2.5 text-muted-foreground">
                                  {entry.engagement
                                    ? projectName.get(entry.engagement) ?? "—"
                                    : "—"}
                                </td>
                                <td className="px-4 py-2.5 text-muted-foreground">
                                  {entry.category ? categoryName.get(entry.category) ?? "—" : "—"}
                                </td>
                                <td className="whitespace-nowrap px-4 py-2.5 text-muted-foreground">
                                  {startT}
                                  {endT ? `–${endT}` : " · running"}
                                </td>
                                <td className="px-4 py-2.5">
                                  <div className="flex items-center gap-1.5">
                                    {!entry.billable && (
                                      <Badge variant="secondary">Non-billable</Badge>
                                    )}
                                    {entry.billedAt && <Badge variant="secondary">Invoiced</Badge>}
                                  </div>
                                </td>
                                <td className="whitespace-nowrap px-4 py-2.5 text-right font-mono tabular-nums">
                                  {formatDuration(entry.durationSeconds ?? elapsedSeconds(entry))}
                                </td>
                              </tr>
                            );
                          })}
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

export default TimePage;
