import { Bell, Mail } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import {
  DEFAULT_NOTIFICATION_MATRIX,
  NOTIFICATION_ROWS,
  allOnMatrix,
} from "@/lib/notificationPrefs";
import type { NotificationChannel } from "@/contexts/AuthContext";

interface NotificationMatrixProps {
  value: Record<string, NotificationChannel>;
  onChange: (next: Record<string, NotificationChannel>) => void;
  disabled?: boolean;
}

/**
 * Per-event × {in-app, email} toggle grid. Sends the whole matrix object on
 * each change (the server merges preference keys shallowly).
 */
const NotificationMatrix = ({
  value,
  onChange,
  disabled,
}: NotificationMatrixProps) => {
  const channelFor = (key: string): NotificationChannel =>
    value[key] ?? DEFAULT_NOTIFICATION_MATRIX[key] ?? { inApp: true, email: true };

  const setCell = (key: string, channel: keyof NotificationChannel, on: boolean) =>
    onChange({ ...value, [key]: { ...channelFor(key), [channel]: on } });

  return (
    <div className="space-y-3" data-testid="notification-matrix">
      <div className="flex items-center justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={disabled}
          onClick={() => onChange(allOnMatrix())}
          data-testid="notif-matrix-all-on"
        >
          All on
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={disabled}
          onClick={() => onChange({ ...DEFAULT_NOTIFICATION_MATRIX })}
          data-testid="notif-matrix-defaults"
        >
          Match defaults
        </Button>
      </div>

      <div className="overflow-hidden rounded-md border">
        <div className="grid grid-cols-[1fr_5rem_5rem] items-center border-b bg-muted/40 px-4 py-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
          <span>Event</span>
          <span className="flex items-center justify-center gap-1">
            <Bell className="h-3.5 w-3.5" /> In-app
          </span>
          <span className="flex items-center justify-center gap-1">
            <Mail className="h-3.5 w-3.5" /> Email
          </span>
        </div>
        {NOTIFICATION_ROWS.map((row) => {
          const channel = channelFor(row.key);
          return (
            <div
              key={row.key}
              className="grid grid-cols-[1fr_5rem_5rem] items-center border-b px-4 py-3 last:border-b-0"
              data-testid={`notif-row-${row.key}`}
            >
              <div className="pr-3">
                <p className="text-sm font-medium">{row.label}</p>
                <p className="text-xs text-muted-foreground">{row.description}</p>
              </div>
              <div className="flex justify-center">
                <Switch
                  checked={channel.inApp}
                  disabled={disabled}
                  onCheckedChange={(on) => setCell(row.key, "inApp", on)}
                  data-testid={`notif-${row.key}-inApp`}
                />
              </div>
              <div className="flex justify-center">
                <Switch
                  checked={channel.email}
                  disabled={disabled}
                  onCheckedChange={(on) => setCell(row.key, "email", on)}
                  data-testid={`notif-${row.key}-email`}
                />
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
};

export default NotificationMatrix;
