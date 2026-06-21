import { useState } from "react";
import { Bell, Clock, Plus, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { cn } from "@/lib/utils";
import EnablePushPrompt from "@/components/notifications/EnablePushPrompt";
import {
  describeReminder,
  reminderKey,
  type Reminder,
  type ReminderUnit,
} from "@/components/tasks/taskHelpers";

/**
 * Reminders list + add-form for the task drawer (#227 tasks redesign).
 * Renders each reminder with a RELATIVE / ABSOLUTE badge and a "repeats
 * daily" sub-line, plus an inline composer for new reminders. Relative
 * reminders fire a fixed offset before the due date; absolute reminders fire
 * at a wall-clock time and need no due date. Persists the whole array.
 */
interface RemindersEditorProps {
  value: Reminder[] | null;
  /** Relative reminders anchor to this; absolute reminders don't need it. */
  dueDate: string | null;
  onChange: (next: Reminder[] | null) => void | Promise<void>;
}

const UNITS: { value: ReminderUnit; label: string }[] = [
  { value: "minutes", label: "minutes" },
  { value: "hours", label: "hours" },
  { value: "days", label: "days" },
];

// "2026-06-01T09:00" (datetime-local) → ISO with offset, and back.
const toLocalInput = (iso: string): string => {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const RemindersEditor = ({ value, dueDate, onChange }: RemindersEditorProps) => {
  const reminders = value ?? [];
  const [adding, setAdding] = useState(false);
  const [mode, setMode] = useState<"relative" | "absolute">("relative");
  const [relValue, setRelValue] = useState(15);
  const [relUnit, setRelUnit] = useState<ReminderUnit>("minutes");
  const [absAt, setAbsAt] = useState("");
  const [repeat, setRepeat] = useState(false);

  const resetForm = () => {
    setAdding(false);
    setMode("relative");
    setRelValue(15);
    setRelUnit("minutes");
    setAbsAt("");
    setRepeat(false);
  };

  const handleRemove = (target: Reminder) => {
    const next = reminders.filter((r) => reminderKey(r) !== reminderKey(target));
    void onChange(next.length === 0 ? null : next);
  };

  const handleAdd = () => {
    let reminder: Reminder;
    if (mode === "relative") {
      reminder = {
        type: "relative",
        value: Math.max(0, relValue),
        unit: relUnit,
        repeat,
      };
    } else {
      if (absAt === "") return;
      reminder = {
        type: "absolute",
        at: new Date(absAt).toISOString(),
        repeat,
      };
    }
    // Skip exact duplicates (same canonical key).
    if (reminders.some((r) => reminderKey(r) === reminderKey(reminder))) {
      resetForm();
      return;
    }
    void onChange([...reminders, reminder]);
    resetForm();
  };

  return (
    <div className="space-y-2" data-testid="reminders-editor">
      <div className="flex items-center justify-between">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
          Reminders
        </p>
        {reminders.length > 0 && (
          <span className="text-xs text-muted-foreground">
            {reminders.length} active
          </span>
        )}
      </div>

      {reminders.length > 0 && (
        <ul className="space-y-1.5">
          {reminders.map((r) => (
            <li
              key={reminderKey(r)}
              className="flex items-center gap-2 rounded-md border border-input px-2.5 py-2"
              data-testid="reminder-item"
            >
              {r.type === "relative" ? (
                <Bell className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
              ) : (
                <Clock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
              )}
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm">{describeReminder(r)}</p>
                {r.repeat && (
                  <p className="text-xs text-muted-foreground">
                    Repeats daily until done
                  </p>
                )}
              </div>
              <span
                className={cn(
                  "rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide",
                  r.type === "relative"
                    ? "bg-primary/15 text-primary"
                    : "bg-accent text-accent-foreground",
                )}
              >
                {r.type}
              </span>
              <button
                type="button"
                onClick={() => handleRemove(r)}
                className="text-muted-foreground hover:text-destructive"
                aria-label="Remove reminder"
                data-testid="reminder-remove"
              >
                <Trash2 className="h-3.5 w-3.5" />
              </button>
            </li>
          ))}
        </ul>
      )}

      {adding ? (
        <div className="space-y-3 rounded-md border border-input p-3" data-testid="reminder-form">
          <div className="grid grid-cols-2 gap-1 rounded-md bg-muted/50 p-1">
            <button
              type="button"
              onClick={() => setMode("relative")}
              aria-pressed={mode === "relative"}
              className={cn(
                "rounded px-2 py-1 text-xs font-medium transition",
                mode === "relative"
                  ? "bg-primary text-primary-foreground"
                  : "text-muted-foreground hover:text-foreground",
              )}
              data-testid="reminder-mode-relative"
            >
              Relative
            </button>
            <button
              type="button"
              onClick={() => setMode("absolute")}
              aria-pressed={mode === "absolute"}
              className={cn(
                "rounded px-2 py-1 text-xs font-medium transition",
                mode === "absolute"
                  ? "bg-primary text-primary-foreground"
                  : "text-muted-foreground hover:text-foreground",
              )}
              data-testid="reminder-mode-absolute"
            >
              Absolute
            </button>
          </div>

          {mode === "relative" ? (
            <div className="flex items-center gap-2">
              <Input
                type="number"
                min={0}
                max={999}
                value={relValue}
                onChange={(e) =>
                  setRelValue(Math.max(0, Number.parseInt(e.target.value, 10) || 0))
                }
                className="h-8 w-16"
                data-testid="reminder-rel-value"
              />
              <select
                value={relUnit}
                onChange={(e) => setRelUnit(e.target.value as ReminderUnit)}
                className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                data-testid="reminder-rel-unit"
              >
                {UNITS.map((u) => (
                  <option key={u.value} value={u.value}>
                    {u.label}
                  </option>
                ))}
              </select>
              <span className="text-sm text-muted-foreground">before due</span>
            </div>
          ) : null}
          {mode === "relative" && !dueDate && (
            <p className="text-xs text-muted-foreground" data-testid="reminder-rel-dormant">
              Fires once this task has a due date.
            </p>
          )}
          {mode === "absolute" && (
            <input
              type="datetime-local"
              value={absAt === "" ? "" : toLocalInput(absAt)}
              onChange={(e) =>
                setAbsAt(e.target.value === "" ? "" : new Date(e.target.value).toISOString())
              }
              className="h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
              data-testid="reminder-abs-at"
            />
          )}

          <div className="flex items-center justify-between">
            <span className="text-sm">Repeat daily until done</span>
            <Switch
              checked={repeat}
              onCheckedChange={setRepeat}
              data-testid="reminder-repeat"
            />
          </div>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" size="sm" onClick={resetForm}>
              Cancel
            </Button>
            <Button
              type="button"
              size="sm"
              onClick={handleAdd}
              data-testid="reminder-add-confirm"
            >
              Add reminder
            </Button>
          </div>
        </div>
      ) : (
        <button
          type="button"
          onClick={() => setAdding(true)}
          className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          data-testid="reminder-add"
        >
          <Plus className="h-3.5 w-3.5" /> Add reminder
        </button>
      )}

      {reminders.length > 0 && <EnablePushPrompt />}
    </div>
  );
};

export default RemindersEditor;
