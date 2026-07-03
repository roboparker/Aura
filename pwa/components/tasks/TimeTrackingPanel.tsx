import { useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Play, Square } from "lucide-react";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import {
  TimeEntry,
  elapsedSeconds,
  formatClock,
  formatDuration,
  isRunning,
} from "@/lib/timeEntryTypes";
import { Button } from "@/components/ui/button";

const MERGE_PATCH = "application/merge-patch+json";

interface Props {
  taskIri: string;
  projectIri: string | null;
}

/**
 * Per-task time tracking inside the task drawer (#444): start/stop a timer
 * scoped to this task, see the total tracked, and the individual entries. Sends
 * the active space IRI (the backend validates the task belongs to it).
 */
const TimeTrackingPanel = ({ taskIri, projectIri }: Props) => {
  const { activeSpace } = useActiveSpace();
  const queryClient = useQueryClient();
  const [nowTick, setNowTick] = useState(() => Date.now());
  const [error, setError] = useState<string | null>(null);

  const spaceIri = activeSpace?.["@id"] ?? null;

  const entriesQuery = useQuery({
    queryKey: ["time_entries", "task", taskIri],
    queryFn: () =>
      apiGetCollection<TimeEntry>(`/time_entries?task=${encodeURIComponent(taskIri)}`, {
        errorMessage: "Failed to load time entries.",
      }),
  });
  const entries = useMemo(() => entriesQuery.data ?? [], [entriesQuery.data]);
  const running = entries.find(isRunning) ?? null;
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ["time_entries"] });
  };

  useEffect(() => {
    if (!running) return;
    const t = setInterval(() => setNowTick(Date.now()), 1000);
    return () => clearInterval(t);
  }, [running]);

  const totalSeconds = useMemo(
    () => entries.reduce((sum, e) => sum + (e.durationSeconds ?? 0), 0),
    [entries],
  );

  const start = useMutation({
    mutationFn: () =>
      apiSend<TimeEntry>("POST", "/time_entries", {
        errorMessage: "Failed to start the timer.",
        body: {
          task: taskIri,
          startedAt: new Date().toISOString(),
          billable: true,
          ...(projectIri ? { project: projectIri } : {}),
          ...(spaceIri ? { space: spaceIri } : {}),
        },
      }),
    onSuccess: () => {
      setError(null);
      refresh();
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Failed to start the timer."),
  });

  const stop = useMutation({
    mutationFn: (entry: TimeEntry) =>
      apiSend<TimeEntry>("PATCH", entry["@id"], {
        contentType: MERGE_PATCH,
        errorMessage: "Failed to stop the timer.",
        body: { endedAt: new Date().toISOString() },
      }),
    onSuccess: () => refresh(),
    onError: (e) => setError(e instanceof Error ? e.message : "Failed to stop the timer."),
  });

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-3">
        <span className="text-sm text-muted-foreground">
          {totalSeconds > 0 ? `Total: ${formatDuration(totalSeconds)}` : "No time tracked"}
        </span>
        {running ? (
          <Button
            variant="destructive"
            size="sm"
            onClick={() => stop.mutate(running)}
            disabled={stop.isPending}
            className="gap-1.5"
          >
            <Square className="size-3.5" />
            {formatClock(elapsedSeconds(running, nowTick))}
          </Button>
        ) : (
          <Button
            variant="outline"
            size="sm"
            onClick={() => start.mutate()}
            disabled={start.isPending}
            className="gap-1.5"
          >
            <Play className="size-3.5" /> Start timer
          </Button>
        )}
      </div>

      {error && <p className="text-xs text-destructive">{error}</p>}

      {entries.length > 0 && (
        <ul className="space-y-1">
          {entries.map((entry) => (
            <li
              key={entry["@id"]}
              className="flex items-center justify-between gap-2 text-xs text-muted-foreground"
            >
              <span className="truncate">
                {new Date(entry.startedAt).toLocaleDateString(undefined, {
                  month: "short",
                  day: "numeric",
                })}
                {entry.description ? ` · ${entry.description}` : ""}
              </span>
              <span className="shrink-0 font-mono tabular-nums">
                {isRunning(entry)
                  ? formatClock(elapsedSeconds(entry, nowTick))
                  : formatDuration(entry.durationSeconds ?? 0)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

export default TimeTrackingPanel;
