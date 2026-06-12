import { useAuth } from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import { DEFAULT_NOTIFICATION_MATRIX } from "@/lib/notificationPrefs";
import SettingsShell from "@/components/settings/SettingsShell";
import SaveIndicator from "@/components/settings/SaveIndicator";
import NotificationMatrix from "@/components/settings/NotificationMatrix";
import EmailDigestControl from "@/components/settings/EmailDigestControl";
import QuietHoursControl from "@/components/settings/QuietHoursControl";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { Switch } from "@/components/ui/switch";

const NotificationsPage = () => {
  const { user } = useAuth();
  const { persist, saveStatus, saveError } = usePreferencePersist();
  const prefs = user?.preferences;
  const disabled = !user;

  const pushPermission =
    typeof window !== "undefined" && "Notification" in window
      ? Notification.permission
      : "default";

  const matrix = prefs?.notificationMatrix ?? DEFAULT_NOTIFICATION_MATRIX;
  const digest = prefs?.emailDigest ?? { mode: "realtime", hour: 8 };
  const quietHours = prefs?.quietHours ?? {
    enabled: false,
    start: "22:00",
    end: "07:00",
  };

  return (
    <SettingsShell
      active="notifications"
      title="Notifications"
      description="Pick which Madori events reach you, and where."
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
        <CardContent>
          <NotificationMatrix
            value={matrix}
            disabled={disabled}
            onChange={(next) => void persist({ notificationMatrix: next })}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Email &amp; quiet hours</CardTitle>
        </CardHeader>
        <CardContent className="space-y-6">
          <EmailDigestControl
            value={digest}
            disabled={disabled}
            onChange={(next) =>
              // notificationFrequency is the canonical cadence the backend
              // reads; keep it in sync with the digest mode.
              void persist({ emailDigest: next, notificationFrequency: next.mode })
            }
          />
          <Separator />
          <QuietHoursControl
            value={quietHours}
            timezone={prefs?.timezone ?? "UTC"}
            disabled={disabled}
            onChange={(next) => void persist({ quietHours: next })}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Push</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2">
          <div className="flex items-start justify-between gap-4">
            <div className="min-w-0 flex-1">
              <Label htmlFor="push-toggle" className="text-sm font-medium">
                Browser push notifications
              </Label>
              <p className="text-xs text-muted-foreground">
                Send browser push when an in-app event fires.
              </p>
            </div>
            <Switch
              id="push-toggle"
              checked={prefs?.pushNotificationsEnabled ?? false}
              disabled={disabled}
              onCheckedChange={(on) =>
                void persist({ pushNotificationsEnabled: on })
              }
              data-testid="settings-push-toggle"
            />
          </div>
          <p className="text-xs text-muted-foreground">
            Browser permission:{" "}
            <span className="font-medium" data-testid="push-permission">
              {pushPermission}
            </span>
            .
          </p>
        </CardContent>
      </Card>
    </SettingsShell>
  );
};

export default NotificationsPage;
