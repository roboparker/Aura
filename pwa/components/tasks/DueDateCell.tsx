import { useState } from "react";
import { AlertTriangle, Bell, Repeat, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import RecurrencePicker from "@/components/tasks/RecurrencePicker";
import RemindersField from "@/components/tasks/RemindersField";
import {
  formatDueDate,
  formatRecurrenceSummary,
  isoToLocalDate,
  localDateToIso,
  type DueDateStatus,
  type RecurrenceRule,
  type Reminder,
} from "@/components/tasks/taskHelpers";
import { cn } from "@/lib/utils";

interface DueDateCellProps {
  value: string | null;
  onChange: (next: string | null) => void | Promise<void>;
  ariaLabel: string;
  testIdPrefix: string;
  /** Optional recurrence controls. When `recurrenceValue` is provided we render
   *  a frequency + interval picker in the same popover; clearing the date
   *  also clears the rule (a recurrence with no anchor is invalid server-side). */
  recurrenceValue?: RecurrenceRule | null;
  onRecurrenceChange?: (next: RecurrenceRule | null) => void | Promise<void>;
  /** Optional reminder controls. When `remindersValue` is provided we render
   *  checkboxes for each allowed offset inside the same popover. Clearing
   *  the date also clears reminders (the server-side validator rejects
   *  reminders without an anchor). */
  remindersValue?: Reminder[] | null;
  onRemindersChange?: (next: Reminder[] | null) => void | Promise<void>;
  /** Toggles overdue/today colouring. Completed tasks pass `"none"` so a missed
   *  deadline doesn't keep glowing red after the work is done. */
  status?: DueDateStatus;
}

const DueDateCell = ({
  value,
  onChange,
  ariaLabel,
  testIdPrefix,
  recurrenceValue = null,
  onRecurrenceChange,
  remindersValue = null,
  onRemindersChange,
  status = "none",
}: DueDateCellProps) => {
  const [open, setOpen] = useState(false);
  const selected = isoToLocalDate(value);
  const reminderCount = remindersValue?.length ?? 0;

  const handleSelect = (date: Date | undefined) => {
    setOpen(false);
    const next = date ? localDateToIso(date) : null;
    if (next === value) return;
    void onChange(next);
  };

  const handleClear = () => {
    setOpen(false);
    if (value === null) return;
    // Recurrence and reminders are meaningless without a date anchor; drop
    // them together to avoid leaving the row in a state the server-side
    // validator rejects.
    if (recurrenceValue && onRecurrenceChange) {
      void onRecurrenceChange(null);
    }
    if (remindersValue && remindersValue.length > 0 && onRemindersChange) {
      void onRemindersChange(null);
    }
    void onChange(null);
  };


  const dateClassName = cn(
    "flex h-full min-h-11 w-full items-center gap-1 rounded-none border border-transparent px-2 text-left text-sm hover:border-input focus-visible:border-input group-hover/due:border-input",
    status === "overdue" && "text-destructive font-medium",
    status === "today" && "text-amber-600 dark:text-amber-400 font-medium",
  );

  return (
    <div className="group/due relative flex h-full w-full items-center">
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        {value ? (
          <button
            type="button"
            aria-label={
              status === "overdue"
                ? `${ariaLabel} (overdue)`
                : status === "today"
                  ? `${ariaLabel} (due today)`
                  : ariaLabel
            }
            className={dateClassName}
            data-testid={testIdPrefix}
            data-status={status}
          >
            {status === "overdue" && (
              <AlertTriangle
                className="h-3.5 w-3.5"
                aria-hidden="true"
                data-testid={`${testIdPrefix}-overdue-icon`}
              />
            )}
            <span>{formatDueDate(value)}</span>
            {recurrenceValue && (
              <Repeat
                className="h-3 w-3 text-muted-foreground"
                aria-label={formatRecurrenceSummary(recurrenceValue)}
                data-testid={`${testIdPrefix}-repeat-icon`}
              />
            )}
            {reminderCount > 0 && (
              <Bell
                className="h-3 w-3 text-muted-foreground"
                aria-label={`${reminderCount} reminder${reminderCount === 1 ? "" : "s"} set`}
                data-testid={`${testIdPrefix}-reminder-icon`}
              />
            )}
          </button>
        ) : (
          <button
            type="button"
            aria-label={ariaLabel}
            className="flex h-full min-h-11 w-full items-center rounded-none border border-transparent px-2 text-left text-sm text-muted-foreground hover:border-input focus-visible:border-input group-hover/due:border-input"
            data-testid={`${testIdPrefix}-add`}
          >
            Add date
          </button>
        )}
      </PopoverTrigger>
      <PopoverContent
        // Cap the popover height and let it scroll internally — calendar +
        // recurrence picker + reminders + clear stack tall enough to spill
        // off short viewports otherwise. Radix exposes its own
        // `--radix-popover-content-available-height` so the cap also
        // tracks the actual gap between trigger and viewport edge.
        className="w-auto p-0 max-h-[min(560px,var(--radix-popover-content-available-height))] overflow-y-auto"
        align="start"
        data-testid={`${testIdPrefix}-popover`}
      >
        <Calendar
          mode="single"
          selected={selected}
          onSelect={handleSelect}
          autoFocus
        />
        {onRecurrenceChange && value && (
          <RecurrencePicker
            value={recurrenceValue}
            onChange={onRecurrenceChange}
            testIdPrefix={`${testIdPrefix}-recurrence`}
          />
        )}
        {onRemindersChange && value && (
          <div
            className="border-t p-2 space-y-1.5 min-w-56"
            data-testid={`${testIdPrefix}-reminders`}
          >
            <p className="text-xs font-medium text-muted-foreground px-1">
              Remind me
            </p>
            <RemindersField
              value={remindersValue}
              dueDate={value}
              onChange={onRemindersChange}
              testIdPrefix={`${testIdPrefix}-reminder`}
            />
          </div>
        )}
        {value && (
          <div className="border-t p-2">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="w-full justify-center"
              onClick={handleClear}
              data-testid={`${testIdPrefix}-clear`}
            >
              Clear
            </Button>
          </div>
        )}
      </PopoverContent>
    </Popover>
      {value && (
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            handleClear();
          }}
          aria-label="Clear due date"
          className="absolute right-1 top-1/2 z-10 -translate-y-1/2 rounded p-0.5 text-muted-foreground opacity-0 transition-opacity hover:text-foreground group-hover/due:opacity-100 focus-visible:opacity-100"
          data-testid={`${testIdPrefix}-clear-inline`}
        >
          <X className="size-3.5" />
        </button>
      )}
    </div>
  );
};

export default DueDateCell;
