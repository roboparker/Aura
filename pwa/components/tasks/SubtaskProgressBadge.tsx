import { ListChecks } from "lucide-react";
import { cn } from "@/lib/utils";

export interface SubtaskProgress {
  total: number;
  completed: number;
}

/**
 * "2/5" subtask rollup for a task row or card.
 *
 * The count comes batched off the task collection (`subtaskProgress`, attached
 * by the API's TaskSubtaskProgressProvider), so rendering one per row costs no
 * extra request. Renders nothing when the task has no subtasks — a 0/0 badge on
 * every childless task would be noise on a full board.
 *
 * Completion is informational only: a parent is never auto-completed or blocked
 * by its children, so an all-done rollup is tinted, not treated as a state.
 */
const SubtaskProgressBadge = ({
  progress,
  className,
}: {
  progress: SubtaskProgress | undefined;
  className?: string;
}) => {
  if (!progress || progress.total === 0) return null;

  const { completed, total } = progress;
  const allDone = completed === total;

  return (
    <span
      title={`${completed} of ${total} subtasks complete`}
      aria-label={`${completed} of ${total} subtasks complete`}
      // Distinct from the drawer's own "x/y" readout (`subtask-progress` in
      // SubtasksPanel) so selectors can target the row badge unambiguously.
      data-testid="subtask-progress-badge"
      className={cn(
        "inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-xs tabular-nums",
        allDone
          ? "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400"
          : "bg-muted text-muted-foreground",
        className,
      )}
    >
      <ListChecks className="size-3" aria-hidden />
      {completed}/{total}
    </span>
  );
};

export default SubtaskProgressBadge;
