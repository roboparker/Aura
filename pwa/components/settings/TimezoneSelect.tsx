import { useMemo } from "react";
import { Button } from "@/components/ui/button";
import {
  Combobox,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
} from "@/components/ui/combobox";
import { Label } from "@/components/ui/label";

/**
 * IANA time-zone picker. Controlled — the parent owns the value and the
 * save. Options come from the runtime's `Intl.supportedValuesOf`, falling
 * back to a small common list on older engines. The "Detect" button reads
 * the browser's current zone.
 */
interface TimezoneSelectProps {
  value: string;
  onChange: (tz: string) => void;
  disabled?: boolean;
}

const FALLBACK_ZONES = [
  "UTC",
  "America/New_York",
  "America/Chicago",
  "America/Denver",
  "America/Los_Angeles",
  "America/Sao_Paulo",
  "Europe/London",
  "Europe/Paris",
  "Europe/Berlin",
  "Europe/Moscow",
  "Africa/Johannesburg",
  "Asia/Dubai",
  "Asia/Kolkata",
  "Asia/Shanghai",
  "Asia/Tokyo",
  "Australia/Sydney",
  "Pacific/Auckland",
];

const supportedTimeZones = (): string[] => {
  const intl = Intl as typeof Intl & {
    supportedValuesOf?: (key: string) => string[];
  };
  try {
    const zones = intl.supportedValuesOf?.("timeZone");
    if (zones && zones.length > 0) {
      return zones.includes("UTC") ? zones : ["UTC", ...zones];
    }
  } catch {
    /* fall through */
  }
  return FALLBACK_ZONES;
};

const detectTimeZone = (): string => {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
  } catch {
    return "UTC";
  }
};

/** "America/New_York · GMT-4" — best-effort offset label. */
const labelForZone = (zone: string): string => {
  try {
    const parts = new Intl.DateTimeFormat("en-US", {
      timeZone: zone,
      timeZoneName: "shortOffset",
    }).formatToParts(new Date());
    const offset = parts.find((p) => p.type === "timeZoneName")?.value;
    return offset ? `${zone} · ${offset}` : zone;
  } catch {
    return zone;
  }
};

const TimezoneSelect = ({ value, onChange, disabled }: TimezoneSelectProps) => {
  const zones = useMemo(() => supportedTimeZones(), []);

  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between">
        <Label htmlFor="settings-timezone">Time zone</Label>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-7 px-2 text-xs"
          disabled={disabled}
          onClick={() => onChange(detectTimeZone())}
          data-testid="settings-timezone-detect"
        >
          Detect
        </Button>
      </div>
      <Combobox<string, false>
        items={zones}
        value={value}
        disabled={disabled}
        onValueChange={(z) => z && onChange(z)}
        itemToStringLabel={labelForZone}
        itemToStringValue={(z) => z}
      >
        <ComboboxInput
          id="settings-timezone"
          placeholder="Search time zone…"
          data-testid="settings-timezone-input"
        />
        <ComboboxContent>
          <ComboboxEmpty>No matching time zone.</ComboboxEmpty>
          <ComboboxList>
            {(z: string) => (
              <ComboboxItem key={z} value={z}>
                {labelForZone(z)}
              </ComboboxItem>
            )}
          </ComboboxList>
        </ComboboxContent>
      </Combobox>
      <p className="text-xs text-muted-foreground">
        Used by scheduling &amp; reminders.
      </p>
    </div>
  );
};

export default TimezoneSelect;
