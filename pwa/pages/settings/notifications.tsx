import { useAuth, type UserPreferences } from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import SettingsShell from "@/components/settings/SettingsShell";
import SaveIndicator from "@/components/settings/SaveIndicator";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { cn } from "@/lib/utils";

const FREQUENCY_OPTIONS: Array<{
  value: UserPreferences["notificationFrequency"];
  label: string;
  description: string;
}> = [
  {
    value: "realtime",
    label: "Real-time",
    description: "Send each reminder as soon as it triggers.",
  },
  {
    value: "hourly",
    label: "Hourly digest",
    description: "Group reminders into one email per hour.",
  },
  {
    value: "daily",
    label: "Daily digest",
    description: "Group reminders into a single morning email.",
  },
];

const NotificationsPage = () => {
  const { user } = useAuth();
  const { persist, saveStatus, saveError } = usePreferencePersist();
  const prefs = user?.preferences;

  const pushPermission =
    typeof window !== "undefined" && "Notification" in window
      ? Notification.permission
      : "default";

  return (
    <SettingsShell
      active="notifications"
      title="Notifications"
      description="Pick which Aura events reach you, and where."
      actions={<SaveIndicator status={saveStatus} />}
    >
      {saveError && (
        <Alert variant="destructive" data-testid="settings-error">
          <AlertDescription>{saveError}</AlertDescription>
        </Alert>
      )}

      <Card data-testid="settings-notifications">
        <CardHeader>
          <CardTitle>Delivery</CardTitle>
        </CardHeader>
        <CardContent className="space-y-6">
          <ToggleRow
            id="email-toggle"
            label="Email notifications"
            description="Receive task reminders via email."
            checked={prefs?.emailNotificationsEnabled ?? true}
            disabled={!user}
            onChange={(checked) =>
              void persist({ emailNotificationsEnabled: checked })
            }
            testId="settings-email-toggle"
          />

          <Separator />

          <div className="space-y-2">
            <ToggleRow
              id="push-toggle"
              label="Push notifications"
              description="Send browser push notifications when reminders fire."
              checked={prefs?.pushNotificationsEnabled ?? false}
              disabled={!user}
              onChange={(checked) =>
                void persist({ pushNotificationsEnabled: checked })
              }
              testId="settings-push-toggle"
            />
            <p className="text-xs text-muted-foreground">
              Browser permission:{" "}
              <span className="font-medium" data-testid="push-permission">
                {pushPermission}
              </span>
              . Push delivery isn’t wired up yet — your preference is saved and
              will take effect once browser push lands.
            </p>
          </div>

          <Separator />

          <div>
            <Label className="text-sm font-medium">Frequency</Label>
            <p className="mb-2 text-xs text-muted-foreground">
              How often you’d like reminder emails grouped.
            </p>
            <div
              className="space-y-2"
              role="radiogroup"
              aria-label="Notification frequency"
            >
              {FREQUENCY_OPTIONS.map(({ value, label, description }) => {
                const selected = prefs?.notificationFrequency === value;
                return (
                  <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={selected}
                    disabled={!user}
                    onClick={() => void persist({ notificationFrequency: value })}
                    className={cn(
                      "w-full rounded-md border px-3 py-2 text-left text-sm transition-colors",
                      selected
                        ? "border-primary bg-primary/10"
                        : "border-input hover:bg-accent",
                    )}
                    data-testid={`settings-frequency-${value}`}
                  >
                    <div className="font-medium">{label}</div>
                    <div className="text-xs text-muted-foreground">
                      {description}
                    </div>
                  </button>
                );
              })}
            </div>
          </div>
        </CardContent>
      </Card>
    </SettingsShell>
  );
};

interface ToggleRowProps {
  id: string;
  label: string;
  description: string;
  checked: boolean;
  disabled?: boolean;
  onChange: (next: boolean) => void;
  testId: string;
}

const ToggleRow = ({
  id,
  label,
  description,
  checked,
  disabled,
  onChange,
  testId,
}: ToggleRowProps) => (
  <div className="flex items-start justify-between gap-4">
    <div className="min-w-0 flex-1">
      <Label htmlFor={id} className="cursor-pointer text-sm font-medium">
        {label}
      </Label>
      <p className="text-xs text-muted-foreground">{description}</p>
    </div>
    <input
      id={id}
      type="checkbox"
      role="switch"
      checked={checked}
      disabled={disabled}
      onChange={(e) => onChange(e.target.checked)}
      className="mt-1 h-4 w-4 cursor-pointer"
      data-testid={testId}
    />
  </div>
);

export default NotificationsPage;
