import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import type { QuietHoursPref } from "@/contexts/AuthContext";

interface QuietHoursControlProps {
  value: QuietHoursPref;
  timezone: string;
  onChange: (next: QuietHoursPref) => void;
  disabled?: boolean;
}

/**
 * Quiet hours window — suppresses email + push while active (in-app rows
 * still land). Interpreted in the user's timezone.
 */
const QuietHoursControl = ({
  value,
  timezone,
  onChange,
  disabled,
}: QuietHoursControlProps) => (
  <div className="space-y-3" data-testid="quiet-hours">
    <div className="flex items-start justify-between gap-4">
      <div>
        <p className="text-sm font-medium">Quiet hours</p>
        <p className="text-xs text-muted-foreground">
          Mute email &amp; push during these hours — interpreted in your
          timezone ({timezone}).
        </p>
      </div>
      <Switch
        checked={value.enabled}
        disabled={disabled}
        onCheckedChange={(on) => onChange({ ...value, enabled: on })}
        data-testid="quiet-hours-toggle"
      />
    </div>

    {value.enabled && (
      <div className="flex flex-wrap items-center gap-4">
        <div className="flex items-center gap-2">
          <Label htmlFor="quiet-start" className="text-sm">
            From
          </Label>
          <Input
            id="quiet-start"
            type="time"
            value={value.start}
            disabled={disabled}
            onChange={(e) => onChange({ ...value, start: e.target.value })}
            className="w-32"
            data-testid="quiet-hours-start"
          />
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="quiet-end" className="text-sm">
            To
          </Label>
          <Input
            id="quiet-end"
            type="time"
            value={value.end}
            disabled={disabled}
            onChange={(e) => onChange({ ...value, end: e.target.value })}
            className="w-32"
            data-testid="quiet-hours-end"
          />
        </div>
      </div>
    )}
  </div>
);

export default QuietHoursControl;
