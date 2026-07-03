import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Clock, Play, Square } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
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

/** "YYYY-MM-DDTHH:mm" for a datetime-local input, in local time. */
const toLocalInput = (d: Date): string => {
  const pad = (n: number) => n.toString().padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

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
  const [startedAt, setStartedAt] = useState(() => toLocalInput(new Date()));
  const [endedAt, setEndedAt] = useState("");
  const [billable, setBillable] = useState(true);
  const [rate, setRate] = useState("");
  const [actionError, setActionError] = useState<string | null>(null);
  const [nowTick, setNowTick] = useState(() => Date.now());

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceIri = activeSpace?.["@id"] ?? null;
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

  // Tick once a second only while a timer is running.
  useEffect(() => {
    if (!running) return;
    const t = setInterval(() => setNowTick(Date.now()), 1000);
    return () => clearInterval(t);
  }, [running]);

  const startTimer = useMutation({
    mutationFn: () =>
      apiSend<TimeEntry>("POST", "/time_entries", {
        errorMessage: "Failed to start the timer.",
        body: {
          startedAt: new Date().toISOString(),
          billable: true,
          ...(spaceIri ? { space: spaceIri } : {}),
        },
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
    mutationFn: () => {
      const rateMinor = rate.trim() ? Math.round(parseFloat(rate) * 100) : null;
      return apiSend<TimeEntry>("POST", "/time_entries", {
        errorMessage: "Failed to log time.",
        body: {
          description: description.trim() || null,
          startedAt: new Date(startedAt).toISOString(),
          endedAt: endedAt ? new Date(endedAt).toISOString() : null,
          billable,
          ...(rateMinor !== null ? { rateAmount: rateMinor, rateCurrency: "USD" } : {}),
          ...(spaceIri ? { space: spaceIri } : {}),
        },
      });
    },
    onSuccess: () => {
      setDescription("");
      setEndedAt("");
      setRate("");
      setStartedAt(toLocalInput(new Date()));
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
  const error = actionError || (entriesQuery.isError ? "Failed to load time entries." : null);

  if (authLoading || !isAuthenticated) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

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

          {canCreate && (
            <Card className="mb-6">
              <CardContent className="flex items-center justify-between gap-4 py-4">
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
                    <Button onClick={() => startTimer.mutate()} disabled={startTimer.isPending}>
                      <Play className="size-4" /> Start timer
                    </Button>
                  </>
                )}
              </CardContent>
            </Card>
          )}

          {showComposer && (
            <Card className="mb-6">
              <CardContent className="py-6">
                <form onSubmit={handleLog} className="space-y-4">
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
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1.5">
                      <Label htmlFor="te-start">Start</Label>
                      <Input
                        id="te-start"
                        type="datetime-local"
                        value={startedAt}
                        onChange={(e) => setStartedAt(e.target.value)}
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
                        type="datetime-local"
                        value={endedAt}
                        onChange={(e) => setEndedAt(e.target.value)}
                      />
                    </div>
                  </div>
                  <div className="flex flex-wrap items-center gap-6">
                    <div className="flex items-center gap-2">
                      <Switch id="te-billable" checked={billable} onCheckedChange={setBillable} />
                      <Label htmlFor="te-billable">Billable</Label>
                    </div>
                    <div className="flex items-center gap-2">
                      <Label htmlFor="te-rate">Rate / hr</Label>
                      <Input
                        id="te-rate"
                        type="number"
                        step="0.01"
                        min="0"
                        value={rate}
                        onChange={(e) => setRate(e.target.value)}
                        className="w-28"
                        placeholder="0.00"
                      />
                    </div>
                  </div>
                  <Button type="submit" disabled={logEntry.isPending}>
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
