import { ChevronDown, ChevronUp } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Up/down vote control for a feedback ticket. Presentational: it renders
 * the current net score with an up and a down button, highlights the
 * caller's own vote, and calls `onVote(1 | -1)` — the parent owns the API
 * round-trip and the optimistic update. Re-clicking the active direction
 * is the server-side "clear" toggle; the parent just forwards the same
 * value and the API decides.
 *
 * Vertical by default (board row / detail header); pass
 * `orientation="horizontal"` for a compact inline pill.
 */
export interface VoteControlProps {
  score: number;
  /** The current user's vote: 1, -1, or 0. */
  myVote: number;
  onVote: (value: 1 | -1) => void;
  disabled?: boolean;
  orientation?: "vertical" | "horizontal";
  size?: "sm" | "md";
}

const VoteControl = ({
  score,
  myVote,
  onVote,
  disabled = false,
  orientation = "vertical",
  size = "md",
}: VoteControlProps) => {
  const iconSize = size === "sm" ? "h-4 w-4" : "h-5 w-5";
  const btnBase =
    "flex items-center justify-center rounded-md transition-colors disabled:opacity-50 disabled:pointer-events-none hover:bg-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-ring";
  const btnPad = size === "sm" ? "p-0.5" : "p-1";

  return (
    <div
      className={cn(
        "inline-flex items-center select-none",
        orientation === "vertical" ? "flex-col gap-0.5" : "flex-row gap-1",
      )}
      data-testid="vote-control"
    >
      <button
        type="button"
        onClick={() => onVote(1)}
        disabled={disabled}
        aria-pressed={myVote === 1}
        aria-label="Upvote"
        className={cn(
          btnBase,
          btnPad,
          myVote === 1
            ? "text-emerald-600 dark:text-emerald-400"
            : "text-muted-foreground",
        )}
        data-testid="vote-up"
      >
        <ChevronUp className={iconSize} />
      </button>

      <span
        className={cn(
          "min-w-6 text-center font-semibold tabular-nums",
          size === "sm" ? "text-sm" : "text-base",
          myVote === 1
            ? "text-emerald-600 dark:text-emerald-400"
            : myVote === -1
              ? "text-rose-600 dark:text-rose-400"
              : "text-foreground",
        )}
        data-testid="vote-score"
      >
        {score}
      </span>

      <button
        type="button"
        onClick={() => onVote(-1)}
        disabled={disabled}
        aria-pressed={myVote === -1}
        aria-label="Downvote"
        className={cn(
          btnBase,
          btnPad,
          myVote === -1
            ? "text-rose-600 dark:text-rose-400"
            : "text-muted-foreground",
        )}
        data-testid="vote-down"
      >
        <ChevronDown className={iconSize} />
      </button>
    </div>
  );
};

export default VoteControl;
