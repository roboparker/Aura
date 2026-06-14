import { useCallback, useEffect, useMemo, useState } from "react";
import { Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import {
  WEEKDAYS,
  WEEKDAY_INITIALS,
  WEEKDAY_LABELS,
  type MonthlyMode,
  type RecurrenceEnds,
  type RecurrenceFrequency,
  type RecurrenceRule,
  type Weekday,
} from "@/components/tasks/taskHelpers";

/**
 * The "Repeats" RRULE editor (#227 tasks redesign). A self-contained panel —
 * the drawer mounts it inside a Popover anchored to the Repeats field. Edits
 * a local draft and only commits on Apply, so opening + cancelling never
 * mutates the task. The "Next 3 occurrences" preview is computed server-side
 * via POST /recurrence-preview so it can't disagree with the spawn logic.
 */
interface RecurrenceEditorProps {
  value: RecurrenceRule | null;
  /** Anchor for the occurrence preview + derived monthly/weekly defaults. */
  dueDate: string | null;
  onApply: (next: RecurrenceRule) => void;
  onRemove: () => void;
  onCancel: () => void;
}

const FREQUENCIES: { value: RecurrenceFrequency; label: string }[] = [
  { value: "daily", label: "Daily" },
  { value: "weekly", label: "Weekly" },
  { value: "monthly", label: "Monthly" },
  { value: "yearly", label: "Yearly" },
];

const INTERVAL_NOUN: Record<RecurrenceFrequency, string> = {
  daily: "days",
  weekly: "weeks",
  monthly: "months",
  yearly: "years",
};

const SET_POS_OPTIONS: { value: number; label: string }[] = [
  { value: 1, label: "First" },
  { value: 2, label: "Second" },
  { value: 3, label: "Third" },
  { value: 4, label: "Fourth" },
  { value: 5, label: "Fifth" },
  { value: -1, label: "Last" },
];

const ISO_WEEKDAY: Record<number, Weekday> = {
  1: "MO",
  2: "TU",
  3: "WE",
  4: "TH",
  5: "FR",
  6: "SA",
  7: "SU",
};

type EndsType = "never" | "until" | "count";

const previewFormatter = new Intl.DateTimeFormat(undefined, {
  weekday: "short",
  month: "short",
  day: "numeric",
  year: "numeric",
});

const weekdayFromIso = (iso: string | null): Weekday => {
  if (!iso) return "MO";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "MO";
  const isoDow = d.getDay() === 0 ? 7 : d.getDay();
  return ISO_WEEKDAY[isoDow] ?? "MO";
};

const dayOfMonthFromIso = (iso: string | null): number => {
  if (!iso) return 1;
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? 1 : d.getDate();
};

const RecurrenceEditor = ({
  value,
  dueDate,
  onApply,
  onRemove,
  onCancel,
}: RecurrenceEditorProps) => {
  const [frequency, setFrequency] = useState<RecurrenceFrequency>(
    value?.frequency ?? "weekly",
  );
  const [interval, setIntervalValue] = useState<number>(value?.interval ?? 1);
  const [byDay, setByDay] = useState<Weekday[]>(
    value?.byDay ?? [weekdayFromIso(dueDate)],
  );
  const [monthlyMode, setMonthlyMode] = useState<MonthlyMode>(
    value?.monthlyMode ?? "day",
  );
  const [bySetPos, setBySetPos] = useState<number>(value?.bySetPos ?? 2);
  const [weekdayForMonthly, setWeekdayForMonthly] = useState<Weekday>(
    value?.byDay?.[0] ?? weekdayFromIso(dueDate),
  );
  const [endsType, setEndsType] = useState<EndsType>(value?.ends?.type ?? "never");
  const [until, setUntil] = useState<string>(
    value?.ends?.type === "until" ? value.ends.until : (dueDate?.slice(0, 10) ?? ""),
  );
  const [count, setCount] = useState<number>(
    value?.ends?.type === "count" ? value.ends.count : 6,
  );

  const draft = useMemo<RecurrenceRule>(() => {
    const ends: RecurrenceEnds | null =
      endsType === "until"
        ? { type: "until", until }
        : endsType === "count"
          ? { type: "count", count: Math.max(1, count) }
          : null;
    const rule: RecurrenceRule = {
      frequency,
      interval: Math.max(1, interval),
      ends,
    };
    if (frequency === "weekly" && byDay.length > 0) {
      rule.byDay = WEEKDAYS.filter((d) => byDay.includes(d));
    }
    if (frequency === "monthly") {
      rule.monthlyMode = monthlyMode;
      if (monthlyMode === "weekday") {
        rule.byDay = [weekdayForMonthly];
        rule.bySetPos = bySetPos;
      }
    }
    return rule;
  }, [
    frequency,
    interval,
    byDay,
    monthlyMode,
    bySetPos,
    weekdayForMonthly,
    endsType,
    until,
    count,
  ]);

  const [preview, setPreview] = useState<string[]>([]);

  // Debounced server preview. Skipped when there's no anchor — relative
  // occurrences need a due date to count from.
  useEffect(() => {
    if (!dueDate) {
      setPreview([]);
      return;
    }
    let cancelled = false;
    const handle = setTimeout(() => {
      void (async () => {
        try {
          const res = await fetch(`${ENTRYPOINT}/recurrence-preview`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ dueDate, rule: draft, count: 3 }),
          });
          if (!res.ok || cancelled) return;
          const data: { occurrences?: string[] } = await res.json();
          if (!cancelled) setPreview(data.occurrences ?? []);
        } catch {
          /* preview is advisory — ignore failures */
        }
      })();
    }, 250);
    return () => {
      cancelled = true;
      clearTimeout(handle);
    };
  }, [draft, dueDate]);

  const toggleDay = useCallback((day: Weekday) => {
    setByDay((prev) =>
      prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day],
    );
  }, []);

  const dayOfMonth = dayOfMonthFromIso(dueDate);

  return (
    <div className="w-80 text-sm" data-testid="recurrence-editor">
      <div className="border-b px-3 py-2.5">
        <span className="font-semibold">Repeats</span>{" "}
        <span className="text-xs uppercase tracking-wide text-muted-foreground">
          RRULE editor
        </span>
      </div>

      <div className="max-h-[min(460px,60vh)] space-y-4 overflow-y-auto px-3 py-3">
        {/* Frequency segmented control */}
        <div className="grid grid-cols-4 gap-1 rounded-md bg-muted/50 p-1">
          {FREQUENCIES.map((f) => (
            <button
              key={f.value}
              type="button"
              onClick={() => setFrequency(f.value)}
              aria-pressed={frequency === f.value}
              className={cn(
                "rounded px-2 py-1 text-xs font-medium transition",
                frequency === f.value
                  ? "bg-primary text-primary-foreground"
                  : "text-muted-foreground hover:text-foreground",
              )}
              data-testid={`recurrence-freq-${f.value}`}
            >
              {f.label}
            </button>
          ))}
        </div>

        {/* Interval */}
        <div className="space-y-1">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Interval
          </p>
          <div className="flex items-center gap-2">
            <span className="text-muted-foreground">Every</span>
            <Input
              type="number"
              min={1}
              max={99}
              value={interval}
              onChange={(e) =>
                setIntervalValue(Math.max(1, Number.parseInt(e.target.value, 10) || 1))
              }
              className="h-8 w-16"
              data-testid="recurrence-interval"
            />
            <span className="text-muted-foreground">{INTERVAL_NOUN[frequency]}</span>
          </div>
        </div>

        {/* Weekly: weekday picker */}
        {frequency === "weekly" && (
          <div className="space-y-1.5">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              On <span className="normal-case">· pick one or more days</span>
            </p>
            <div className="flex gap-1">
              {WEEKDAYS.map((day) => (
                <button
                  key={day}
                  type="button"
                  onClick={() => toggleDay(day)}
                  aria-pressed={byDay.includes(day)}
                  aria-label={WEEKDAY_LABELS[day]}
                  className={cn(
                    "flex h-8 w-8 items-center justify-center rounded-full text-xs font-medium transition",
                    byDay.includes(day)
                      ? "bg-primary text-primary-foreground"
                      : "border border-input text-muted-foreground hover:text-foreground",
                  )}
                  data-testid={`recurrence-day-${day}`}
                >
                  {WEEKDAY_INITIALS[day]}
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Monthly: day-of-month vs nth-weekday */}
        {frequency === "monthly" && (
          <div className="space-y-2">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              On
            </p>
            <label className="flex items-center gap-2">
              <input
                type="radio"
                name="monthly-mode"
                checked={monthlyMode === "day"}
                onChange={() => setMonthlyMode("day")}
                data-testid="recurrence-monthly-day"
              />
              <span>Day {dayOfMonth} of the month</span>
            </label>
            <label className="flex items-center gap-2">
              <input
                type="radio"
                name="monthly-mode"
                checked={monthlyMode === "weekday"}
                onChange={() => setMonthlyMode("weekday")}
                data-testid="recurrence-monthly-weekday"
              />
              <span className="flex items-center gap-1">
                The
                <select
                  value={bySetPos}
                  onChange={(e) => setBySetPos(Number.parseInt(e.target.value, 10))}
                  onFocus={() => setMonthlyMode("weekday")}
                  className="h-7 rounded-md border border-input bg-background px-1 text-sm"
                  data-testid="recurrence-setpos"
                >
                  {SET_POS_OPTIONS.map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </select>
                <select
                  value={weekdayForMonthly}
                  onChange={(e) => setWeekdayForMonthly(e.target.value as Weekday)}
                  onFocus={() => setMonthlyMode("weekday")}
                  className="h-7 rounded-md border border-input bg-background px-1 text-sm"
                  data-testid="recurrence-weekday"
                >
                  {WEEKDAYS.map((d) => (
                    <option key={d} value={d}>
                      {WEEKDAY_LABELS[d]}
                    </option>
                  ))}
                </select>
              </span>
            </label>
          </div>
        )}

        {/* Ends */}
        <div className="space-y-2">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Ends
          </p>
          <label className="flex items-center gap-2">
            <input
              type="radio"
              name="ends"
              checked={endsType === "never"}
              onChange={() => setEndsType("never")}
              data-testid="recurrence-ends-never"
            />
            <span>Never</span>
          </label>
          <label className="flex items-center gap-2">
            <input
              type="radio"
              name="ends"
              checked={endsType === "until"}
              onChange={() => setEndsType("until")}
              data-testid="recurrence-ends-until"
            />
            <span>On date</span>
            <input
              type="date"
              value={until}
              onChange={(e) => {
                setUntil(e.target.value);
                setEndsType("until");
              }}
              className="h-7 rounded-md border border-input bg-background px-2 text-sm"
              data-testid="recurrence-until"
            />
          </label>
          <label className="flex items-center gap-2">
            <input
              type="radio"
              name="ends"
              checked={endsType === "count"}
              onChange={() => setEndsType("count")}
              data-testid="recurrence-ends-count"
            />
            <span>After</span>
            <Input
              type="number"
              min={1}
              max={999}
              value={count}
              onChange={(e) => {
                setCount(Math.max(1, Number.parseInt(e.target.value, 10) || 1));
                setEndsType("count");
              }}
              className="h-7 w-16"
              data-testid="recurrence-count"
            />
            <span className="text-muted-foreground">occurrences</span>
          </label>
        </div>

        {/* Preview */}
        <div className="space-y-1">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Next 3 occurrences
          </p>
          {preview.length === 0 ? (
            <p className="text-xs text-muted-foreground">
              {dueDate ? "No further occurrences." : "Set a due date to preview."}
            </p>
          ) : (
            <ol className="space-y-0.5" data-testid="recurrence-preview">
              {preview.map((iso, i) => (
                <li
                  key={iso}
                  className="rounded bg-primary/10 px-2 py-1 text-xs text-foreground"
                >
                  {i + 1}. {previewFormatter.format(new Date(iso))}
                </li>
              ))}
            </ol>
          )}
        </div>
      </div>

      <div className="flex items-center justify-between border-t px-3 py-2.5">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="text-destructive hover:text-destructive"
          onClick={onRemove}
          data-testid="recurrence-remove"
        >
          <Trash2 className="mr-1 h-3.5 w-3.5" /> Remove
        </Button>
        <div className="flex gap-2">
          <Button type="button" variant="outline" size="sm" onClick={onCancel}>
            Cancel
          </Button>
          <Button
            type="button"
            size="sm"
            onClick={() => onApply(draft)}
            data-testid="recurrence-apply"
          >
            Apply
          </Button>
        </div>
      </div>
    </div>
  );
};

export default RecurrenceEditor;
