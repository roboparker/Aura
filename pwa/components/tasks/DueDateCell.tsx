import { useState } from "react";
import { AlertTriangle, Bell, Repeat } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import RecurrencePicker from "@/components/tasks/RecurrencePicker";
import {
  REMINDER_PRESETS,
  addDays,
  addMonths,
  formatDueDate,
  formatRecurrenceSummary,
  isoToLocalDate,
  localDateToIso,
  reminderKey,
  todayLocalMidnight,
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

  const handleQuickPick = (date: Date) => {
    setOpen(false);
    const next = localDateToIso(date);
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

  const today = todayLocalMidnight();
  const quickPicks: Array<{ label: string; date: Date; testId: string }> = [
    { label: "Today", date: today, testId: "today" },
    { label: "Tomorrow", date: addDays(today, 1), testId: "tomorrow" },
    { label: "Next week", date: addDays(today, 7), testId: "next-week" },
    { label: "Next month", date: addMonths(today, 1), testId: "next-month" },
  ];

  const dateClassName = cn(
    "text-left text-sm rounded-sm inline-flex items-center gap-1 hover:text-foreground",
    status === "overdue" && "text-destructive font-medium hover:text-destructive",
    status === "today" && "text-amber-600 dark:text-amber-400 font-medium",
  );

  const togglePreset = (preset: (typeof REMINDER_PRESETS)[number]) => {
    if (!onRemindersChange) return;
    const current = remindersValue ?? [];
    const has = current.some((r) => reminderKey(r) === preset.key);
    const next = has
      ? current.filter((r) => reminderKey(r) !== preset.key)
      : [...current, preset.reminder];
    void onRemindersChange(next.length === 0 ? null : next);
  };

  return (
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
            className="text-left text-sm italic text-muted-foreground/60 hover:text-muted-foreground rounded-sm"
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
        {/* Quick-picks above the calendar — covers the common case of "soon"
            without making the user navigate the grid. */}
        <div className="grid grid-cols-2 gap-1 border-b p-2">
          {quickPicks.map((pick) => (
            <Button
              key={pick.testId}
              type="button"
              variant="ghost"
              size="sm"
              className="justify-start"
              onClick={() => handleQuickPick(pick.date)}
              data-testid={`${testIdPrefix}-quick-${pick.testId}`}
            >
              {pick.label}
            </Button>
          ))}
        </div>
        <Calendar
          mode="single"
          selected={selected}
          onSelect={handleSelect}
          autoFocus
          // Extra top padding so the month nav arrows clear the quick-pick
          // buttons above (the arrows sit at the calendar's top edge).
          className="pt-6"
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
            className="border-t p-2 space-y-1 min-w-56"
            data-testid={`${testIdPrefix}-reminders`}
          >
            <p className="text-xs font-medium text-muted-foreground px-1">
              Remind me
            </p>
            {REMINDER_PRESETS.map((preset) => {
              const checked = (remindersValue ?? []).some(
                (r) => reminderKey(r) === preset.key,
              );
              return (
                <label
                  key={preset.id}
                  className="flex items-center gap-2 px-1 py-1 text-sm cursor-pointer"
                >
                  <input
                    type="checkbox"
                    checked={checked}
                    onChange={() => togglePreset(preset)}
                    className="h-3.5 w-3.5"
                    data-testid={`${testIdPrefix}-reminder-${preset.id}`}
                  />
                  {preset.label}
                </label>
              );
            })}
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
  );
};

export default DueDateCell;
