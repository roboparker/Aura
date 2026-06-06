import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { cn } from "@/lib/utils";
import type { EmailDigestPref, NotificationFrequency } from "@/contexts/AuthContext";

interface EmailDigestControlProps {
  value: EmailDigestPref;
  onChange: (next: EmailDigestPref) => void;
  disabled?: boolean;
}

const MODES: Array<{ value: NotificationFrequency; label: string }> = [
  { value: "realtime", label: "Real-time" },
  { value: "hourly", label: "Hourly" },
  { value: "daily", label: "Daily" },
];

/**
 * Email digest cadence: realtime sends each event; hourly/daily roll
 * lower-priority email into one message. Daily exposes the send hour.
 * "Digest on" = anything other than realtime.
 */
const EmailDigestControl = ({
  value,
  onChange,
  disabled,
}: EmailDigestControlProps) => {
  const digestOn = value.mode !== "realtime";

  return (
    <div className="space-y-3" data-testid="email-digest">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-sm font-medium">Email digest</p>
          <p className="text-xs text-muted-foreground">
            Roll low-priority email into a single message instead of one per event.
          </p>
        </div>
        <Switch
          checked={digestOn}
          disabled={disabled}
          onCheckedChange={(on) =>
            onChange({ ...value, mode: on ? "daily" : "realtime" })
          }
          data-testid="email-digest-toggle"
        />
      </div>

      {digestOn && (
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <Label className="text-xs uppercase tracking-wide text-muted-foreground">
              Schedule
            </Label>
            <div className="flex gap-1">
              {MODES.filter((m) => m.value !== "realtime").map((m) => (
                <button
                  key={m.value}
                  type="button"
                  disabled={disabled}
                  onClick={() => onChange({ ...value, mode: m.value })}
                  className={cn(
                    "rounded-md border px-3 py-1.5 text-sm transition-colors",
                    value.mode === m.value
                      ? "border-primary bg-primary/10"
                      : "border-input hover:bg-accent",
                  )}
                  data-testid={`email-digest-mode-${m.value}`}
                >
                  {m.label}
                </button>
              ))}
            </div>
          </div>

          {value.mode === "daily" && (
            <div className="flex items-center gap-2">
              <Label
                htmlFor="email-digest-hour"
                className="text-xs uppercase tracking-wide text-muted-foreground"
              >
                Send at
              </Label>
              <select
                id="email-digest-hour"
                value={value.hour}
                disabled={disabled}
                onChange={(e) =>
                  onChange({ ...value, hour: Number(e.target.value) })
                }
                className="rounded-md border border-input bg-background px-2 py-1.5 text-sm"
                data-testid="email-digest-hour"
              >
                {Array.from({ length: 24 }, (_, h) => (
                  <option key={h} value={h}>
                    {String(h).padStart(2, "0")}:00
                  </option>
                ))}
              </select>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default EmailDigestControl;
