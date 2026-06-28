import { Repeat } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { cn } from "@/lib/utils";
import {
  WEEKDAYS,
  WEEKDAY_INITIALS,
  WEEKDAY_LABELS,
  ordinalLabel,
  type RecurrenceEnds,
  type RecurrenceFrequency,
  type RecurrenceRule,
  type Weekday,
} from "@/components/tasks/taskHelpers";

interface RecurrencePickerProps {
  value: RecurrenceRule | null;
  /** Anchor for the monthly "day N / Nth weekday" labels + weekly default. */
  dueDate: string | null;
  onChange: (next: RecurrenceRule | null) => void | Promise<void>;
  testIdPrefix: string;
}

const UNITS: { value: RecurrenceFrequency; label: string }[] = [
  { value: "daily", label: "day" },
  { value: "weekly", label: "week" },
  { value: "monthly", label: "month" },
  { value: "yearly", label: "year" },
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

// Which occurrence of its weekday the date is (1st..5th), for "on the Nth …".
const nthFromIso = (iso: string | null): number =>
  Math.min(5, Math.ceil(dayOfMonthFromIso(iso) / 7));

/**
 * Inline recurrence control, Google-Calendar style — lives in the date
 * popover. A "Repeat" toggle reveals the rule menu: "Every N day/week/month/
 * year", weekday picker (weekly), a "day N / Nth weekday" dropdown (monthly),
 * and an Ends dropdown. Live — every change commits the whole rule.
 */
const RecurrencePicker = ({
  value,
  dueDate,
  onChange,
  testIdPrefix,
}: RecurrencePickerProps) => {
  const enabled = value !== null;
  const frequency = value?.frequency ?? "weekly";
  const interval = value?.interval ?? 1;
  const byDay = value?.byDay ?? [];
  const monthlyMode = value?.monthlyMode ?? "day";
  const ends = value?.ends ?? null;
  const endsType = ends?.type ?? "never";

  const dueWeekday = weekdayFromIso(dueDate);
  const dayOfMonth = dayOfMonthFromIso(dueDate);
  const nth = nthFromIso(dueDate);
  const plural = interval > 1 ? "s" : "";

  // Build a normalized rule from the current value + a patch, dropping
  // fields that don't apply to the chosen frequency (server-side validator
  // is strict about that).
  const build = (patch: Partial<RecurrenceRule>): RecurrenceRule => {
    const next: RecurrenceRule = {
      frequency: patch.frequency ?? frequency,
      interval: Math.max(1, patch.interval ?? interval),
      ends: patch.ends !== undefined ? patch.ends : ends,
    };
    if (next.frequency === "weekly") {
      const days = patch.byDay ?? (byDay.length > 0 ? byDay : [dueWeekday]);
      next.byDay = WEEKDAYS.filter((d) => days.includes(d));
      if (next.byDay.length === 0) next.byDay = [dueWeekday];
    }
    if (next.frequency === "monthly") {
      next.monthlyMode = patch.monthlyMode ?? monthlyMode;
      if (next.monthlyMode === "weekday") {
        next.byDay = [dueWeekday];
        next.bySetPos = nth;
      }
    }
    return next;
  };

  const emit = (patch: Partial<RecurrenceRule>) => void onChange(build(patch));

  const toggleRepeat = (on: boolean) => {
    if (on) emit({ frequency: "weekly" });
    else void onChange(null);
  };

  const toggleDay = (day: Weekday) => {
    const nextDays = byDay.includes(day)
      ? byDay.filter((d) => d !== day)
      : [...byDay, day];
    emit({ byDay: nextDays });
  };

  const setEnds = (type: "never" | "until" | "count") => {
    if (type === "never") {
      emit({ ends: null });
    } else if (type === "until") {
      emit({
        ends: {
          type: "until",
          until: ends?.type === "until" ? ends.until : (dueDate?.slice(0, 10) ?? ""),
        },
      });
    } else {
      emit({
        ends: { type: "count", count: ends?.type === "count" ? ends.count : 6 },
      });
    }
  };

  const selectClass =
    "h-8 rounded-md border border-input bg-background px-2 text-sm";

  return (
    <div className="border-t p-2 space-y-2 min-w-56">
      <div className="flex items-center justify-between">
        <span className="flex items-center gap-2 text-sm">
          <Repeat className="h-3.5 w-3.5 text-muted-foreground" aria-hidden />
          Repeat
        </span>
        <Switch
          checked={enabled}
          onCheckedChange={toggleRepeat}
          aria-label="Repeat"
          data-testid={`${testIdPrefix}-toggle`}
        />
      </div>

      {enabled && (
        <div className="space-y-2">
          {/* Every N day/week/month/year */}
          <div className="flex items-center gap-2 text-sm">
            <span className="text-muted-foreground">Every</span>
            <Input
              type="number"
              min={1}
              max={99}
              value={interval}
              onChange={(e) =>
                emit({ interval: Math.max(1, Number.parseInt(e.target.value, 10) || 1) })
              }
              className="h-8 w-14"
              aria-label="Interval"
              data-testid={`${testIdPrefix}-interval`}
            />
            <select
              value={frequency}
              onChange={(e) =>
                emit({ frequency: e.target.value as RecurrenceFrequency })
              }
              className={selectClass}
              aria-label="Frequency"
              data-testid={`${testIdPrefix}-frequency`}
            >
              {UNITS.map((u) => (
                <option key={u.value} value={u.value}>
                  {u.label}
                  {plural}
                </option>
              ))}
            </select>
          </div>

          {/* Frequency-specific control sits in a fixed-height slot so the
              popover doesn't resize when switching daily/weekly/monthly/yearly. */}
          <div className="flex min-h-8 items-center">
            {frequency === "weekly" && (
              <div className="flex gap-1">
                {WEEKDAYS.map((day) => (
                  <button
                    key={day}
                    type="button"
                    onClick={() => toggleDay(day)}
                    aria-pressed={byDay.includes(day)}
                    aria-label={WEEKDAY_LABELS[day]}
                    className={cn(
                      "flex h-7 w-7 items-center justify-center rounded-full text-xs font-medium transition",
                      byDay.includes(day)
                        ? "bg-primary text-primary-foreground"
                        : "border border-input text-muted-foreground hover:text-foreground",
                    )}
                    data-testid={`${testIdPrefix}-day-${day}`}
                  >
                    {WEEKDAY_INITIALS[day]}
                  </button>
                ))}
              </div>
            )}

            {frequency === "monthly" && (
              <select
                value={monthlyMode}
                onChange={(e) =>
                  emit({ monthlyMode: e.target.value as "day" | "weekday" })
                }
                className={cn(selectClass, "w-full")}
                aria-label="Monthly pattern"
                data-testid={`${testIdPrefix}-monthly`}
              >
                <option value="day">On day {dayOfMonth}</option>
                <option value="weekday">
                  On the {ordinalLabel(nth)} {WEEKDAY_LABELS[dueWeekday]}
                </option>
              </select>
            )}
          </div>

          {/* Ends */}
          <div className="flex items-center gap-2 text-sm">
            <span className="text-muted-foreground">Ends</span>
            <select
              value={endsType}
              onChange={(e) =>
                setEnds(e.target.value as "never" | "until" | "count")
              }
              className={selectClass}
              aria-label="Ends"
              data-testid={`${testIdPrefix}-ends`}
            >
              <option value="never">Never</option>
              <option value="until">On date</option>
              <option value="count">After…</option>
            </select>
            {endsType === "until" && (
              <input
                type="date"
                value={ends?.type === "until" ? ends.until : ""}
                onChange={(e) =>
                  emit({
                    ends: { type: "until", until: e.target.value } as RecurrenceEnds,
                  })
                }
                className={selectClass}
                aria-label="Repeat until"
                data-testid={`${testIdPrefix}-until`}
              />
            )}
            {endsType === "count" && (
              <>
                <Input
                  type="number"
                  min={1}
                  max={999}
                  value={ends?.type === "count" ? ends.count : 6}
                  onChange={(e) =>
                    emit({
                      ends: {
                        type: "count",
                        count: Math.max(1, Number.parseInt(e.target.value, 10) || 1),
                      },
                    })
                  }
                  className="h-8 w-16"
                  aria-label="Occurrences"
                  data-testid={`${testIdPrefix}-count`}
                />
                <span className="text-muted-foreground">times</span>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default RecurrencePicker;
