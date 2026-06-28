import { useState } from "react";
import { Plus, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  describeReminder,
  reminderKey,
  type Reminder,
  type ReminderUnit,
} from "@/components/tasks/taskHelpers";

const UNITS: { value: ReminderUnit; label: string }[] = [
  { value: "minutes", label: "minutes" },
  { value: "hours", label: "hours" },
  { value: "days", label: "days" },
];

const UNIT_MINUTES: Record<ReminderUnit, number> = {
  minutes: 1,
  hours: 60,
  days: 1440,
};

// Lead time in minutes, for ordering. Legacy absolute reminders (no longer
// creatable) sort last so they don't interleave with the relative ones.
const leadMinutes = (r: Reminder): number =>
  r.type === "relative"
    ? r.value * UNIT_MINUTES[r.unit]
    : Number.MAX_SAFE_INTEGER;

interface Props {
  value: Reminder[] | null;
  /** Relative reminders fire before this; null shows a dormant hint. */
  dueDate: string | null;
  onChange: (next: Reminder[] | null) => void | Promise<void>;
  testIdPrefix?: string;
}

/**
 * Relative reminders editor: an "Add reminder" button opens an amount + unit
 * (minutes / hours / days) row; existing reminders render as a delete-able
 * list, ordered by how far before the due date they fire.
 */
const RemindersField = ({
  value,
  dueDate,
  onChange,
  testIdPrefix = "reminder",
}: Props) => {
  const reminders = [...(value ?? [])].sort(
    (a, b) => leadMinutes(a) - leadMinutes(b),
  );
  const [adding, setAdding] = useState(false);
  const [amount, setAmount] = useState(10);
  const [unit, setUnit] = useState<ReminderUnit>("minutes");

  const handleRemove = (target: Reminder) => {
    const next = (value ?? []).filter(
      (r) => reminderKey(r) !== reminderKey(target),
    );
    void onChange(next.length === 0 ? null : next);
  };

  const handleAdd = () => {
    const reminder: Reminder = {
      type: "relative",
      value: Math.max(0, amount),
      unit,
      repeat: false,
    };
    setAdding(false);
    setAmount(10);
    setUnit("minutes");
    // Skip exact duplicates (same canonical key).
    if ((value ?? []).some((r) => reminderKey(r) === reminderKey(reminder))) {
      return;
    }
    void onChange([...(value ?? []), reminder]);
  };

  return (
    <div className="space-y-1.5">
      {reminders.length > 0 && (
        <ul className="space-y-1">
          {reminders.map((r) => (
            <li
              key={reminderKey(r)}
              className="flex items-center gap-2 rounded-md border border-input px-2.5 py-1.5 text-sm"
              data-testid={`${testIdPrefix}-item`}
            >
              <span className="min-w-0 flex-1 truncate">
                {describeReminder(r)}
              </span>
              <button
                type="button"
                onClick={() => handleRemove(r)}
                className="shrink-0 text-muted-foreground hover:text-destructive"
                aria-label="Remove reminder"
                data-testid={`${testIdPrefix}-remove`}
              >
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            </li>
          ))}
        </ul>
      )}

      {adding ? (
        <div
          className="flex flex-wrap items-center gap-2"
          data-testid={`${testIdPrefix}-form`}
        >
          <Input
            type="number"
            min={0}
            max={999}
            value={amount}
            onChange={(e) =>
              setAmount(Math.max(0, Number.parseInt(e.target.value, 10) || 0))
            }
            className="h-8 w-16"
            aria-label="Reminder amount"
            data-testid={`${testIdPrefix}-value`}
          />
          <select
            value={unit}
            onChange={(e) => setUnit(e.target.value as ReminderUnit)}
            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
            aria-label="Reminder unit"
            data-testid={`${testIdPrefix}-unit`}
          >
            {UNITS.map((u) => (
              <option key={u.value} value={u.value}>
                {u.label}
              </option>
            ))}
          </select>
          <span className="text-sm text-muted-foreground">before due</span>
          <Button
            type="button"
            size="sm"
            onClick={handleAdd}
            data-testid={`${testIdPrefix}-add-confirm`}
          >
            Add
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setAdding(false)}
          >
            Cancel
          </Button>
        </div>
      ) : (
        <Button
          type="button"
          size="sm"
          onClick={() => setAdding(true)}
          className="gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white"
          data-testid={`${testIdPrefix}-add`}
        >
          <Plus className="h-3.5 w-3.5" /> Add reminder
        </Button>
      )}

      {dueDate === null && reminders.length > 0 && (
        <p
          className="text-xs text-muted-foreground"
          data-testid={`${testIdPrefix}-dormant`}
        >
          Fires once this task has a due date.
        </p>
      )}
    </div>
  );
};

export default RemindersField;
