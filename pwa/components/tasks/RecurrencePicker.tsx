import { Repeat } from "lucide-react";
import { Input } from "@/components/ui/input";
import {
  FREQUENCY_LABELS,
  type RecurrenceFrequency,
  type RecurrenceRule,
} from "@/components/tasks/taskHelpers";

interface RecurrencePickerProps {
  value: RecurrenceRule | null;
  onChange: (next: RecurrenceRule | null) => void | Promise<void>;
  testIdPrefix: string;
}

// Inline recurrence controls — renders inside the date popover so users
// don't have to context-switch to set a repeat. "Off" clears the rule;
// otherwise the (frequency, interval) pair is sent as a single update.
const RecurrencePicker = ({ value, onChange, testIdPrefix }: RecurrencePickerProps) => {
  const frequency: "off" | RecurrenceFrequency = value?.frequency ?? "off";
  const interval = value?.interval ?? 1;

  const handleFrequencyChange = (next: string) => {
    if (next === "off") {
      void onChange(null);
      return;
    }
    void onChange({ frequency: next as RecurrenceFrequency, interval });
  };

  const handleIntervalChange = (raw: string) => {
    const parsed = Number.parseInt(raw, 10);
    if (!Number.isFinite(parsed) || parsed < 1) return;
    if (!value) return;
    void onChange({ frequency: value.frequency, interval: parsed });
  };

  return (
    <div className="border-t p-2 space-y-2 min-w-56">
      <div className="flex items-center gap-2 text-sm">
        <Repeat className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
        <label
          htmlFor={`${testIdPrefix}-frequency`}
          className="text-muted-foreground"
        >
          Repeat
        </label>
        <select
          id={`${testIdPrefix}-frequency`}
          value={frequency}
          onChange={(e) => handleFrequencyChange(e.target.value)}
          className="ml-auto h-8 rounded-md border border-input bg-background px-2 text-sm"
          data-testid={`${testIdPrefix}-frequency`}
        >
          <option value="off">Off</option>
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="monthly">Monthly</option>
          <option value="yearly">Yearly</option>
        </select>
      </div>
      {value && (
        <div className="flex items-center gap-2 text-sm">
          <label
            htmlFor={`${testIdPrefix}-interval`}
            className="text-muted-foreground"
          >
            Every
          </label>
          <Input
            id={`${testIdPrefix}-interval`}
            type="number"
            min={1}
            max={99}
            value={interval}
            onChange={(e) => handleIntervalChange(e.target.value)}
            className="h-8 w-16"
            data-testid={`${testIdPrefix}-interval`}
          />
          <span className="text-muted-foreground">
            {FREQUENCY_LABELS[value.frequency]}
            {interval === 1 ? "" : "s"}
          </span>
        </div>
      )}
    </div>
  );
};

export default RecurrencePicker;
