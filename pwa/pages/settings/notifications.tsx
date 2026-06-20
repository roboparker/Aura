import { useAuth } from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import { useSettingsSection } from "@/lib/useSettingsSection";
import { DEFAULT_NOTIFICATION_MATRIX } from "@/lib/notificationPrefs";
import SettingsShell from "@/components/settings/SettingsShell";
import SaveIndicator from "@/components/settings/SaveIndicator";
import SectionSaveBar from "@/components/settings/SectionSaveBar";
import NotificationMatrix from "@/components/settings/NotificationMatrix";
import EmailDigestControl from "@/components/settings/EmailDigestControl";
import QuietHoursControl from "@/components/settings/QuietHoursControl";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { Switch } from "@/components/ui/switch";

const NotificationsPage = () => {
  const { user } = useAuth();
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

  // Delivery matrix — its own buffered save.
  const delivery = useSettingsSection(matrix, (next) => ({
    notificationMatrix: next,
  }));

  // Email digest + quiet hours share one card and save together.
  const emailQuiet = useSettingsSection({ digest, quietHours }, (next) => ({
    emailDigest: next.digest,
    // notificationFrequency is the canonical cadence the backend reads; keep
    // it in sync with the digest mode.
    notificationFrequency: next.digest.mode,
    quietHours: next.quietHours,
  }));

  // The push toggle is a single browser-permission affordance — kept immediate.
  const push = usePreferencePersist();

  return (
    <SettingsShell
      active="notifications"
      title="Notifications"
      description="Pick which Madori events reach you, and where."
    >
      <Card data-testid="settings-notifications">
        <CardHeader>
          <CardTitle>Delivery</CardTitle>
        </CardHeader>
        <CardContent>
          <NotificationMatrix
            value={delivery.draft}
            disabled={disabled}
            onChange={delivery.setDraft}
          />
          <SectionSaveBar
            dirty={delivery.dirty}
            status={delivery.saveStatus}
            error={delivery.saveError}
            onSave={() => void delivery.save()}
            onDiscard={delivery.discard}
            testId="settings-delivery-save"
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Email &amp; quiet hours</CardTitle>
        </CardHeader>
        <CardContent className="space-y-6">
          <EmailDigestControl
            value={emailQuiet.draft.digest}
            disabled={disabled}
            onChange={(next) =>
              emailQuiet.setDraft({ ...emailQuiet.draft, digest: next })
            }
          />
          <Separator />
          <QuietHoursControl
            value={emailQuiet.draft.quietHours}
            timezone={prefs?.timezone ?? "UTC"}
            disabled={disabled}
            onChange={(next) =>
              emailQuiet.setDraft({ ...emailQuiet.draft, quietHours: next })
            }
          />
          <SectionSaveBar
            dirty={emailQuiet.dirty}
            status={emailQuiet.saveStatus}
            error={emailQuiet.saveError}
            onSave={() => void emailQuiet.save()}
            onDiscard={emailQuiet.discard}
            testId="settings-email-save"
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
            <div className="flex shrink-0 items-center gap-2 pt-0.5">
              <SaveIndicator status={push.saveStatus} />
              <Switch
                id="push-toggle"
                checked={prefs?.pushNotificationsEnabled ?? false}
                disabled={disabled}
                onCheckedChange={(on) =>
                  void push.persist({ pushNotificationsEnabled: on })
                }
                data-testid="settings-push-toggle"
              />
            </div>
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
