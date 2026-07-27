import { useEffect, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import { useSettingsSection } from "@/lib/useSettingsSection";
import { enablePush, pushPermission } from "@/lib/push";
import { DEFAULT_NOTIFICATION_MATRIX, PUSH_PERMISSION_COPY } from "@/lib/notificationPrefs";
import SettingsShell from "@/components/settings/SettingsShell";
import SaveIndicator from "@/components/settings/SaveIndicator";
import SectionSaveBar from "@/components/settings/SectionSaveBar";
import NotificationMatrix from "@/components/settings/NotificationMatrix";
import EmailDigestControl from "@/components/settings/EmailDigestControl";
import QuietHoursControl from "@/components/settings/QuietHoursControl";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { Switch } from "@/components/ui/switch";

const NotificationsPage = () => {
  const { user } = useAuth();
  const prefs = user?.preferences;
  const disabled = !user;

  // Read in an effect, not during render: the server has no Notification API
  // and would always emit "default", so deriving it inline mismatches on
  // hydration for anyone who has already granted or blocked push.
  const [permission, setPermission] = useState<NotificationPermission | "unsupported" | null>(null);
  useEffect(() => setPermission(pushPermission()), []);

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
                onCheckedChange={(on) => {
                  if (!on) {
                    void push.persist({ pushNotificationsEnabled: false });
                    return;
                  }
                  // Turning on subscribes the browser first; only flip the
                  // preference once a subscription is registered.
                  void enablePush().then((result) => {
                    if (result.ok)
                      void push.persist({ pushNotificationsEnabled: true });
                  });
                }}
                data-testid="settings-push-toggle"
              />
            </div>
          </div>
          {/* The browser's own permission vocabulary is misleading here:
              "default" is a spec term meaning *not yet asked*, but reads as
              "this is the normal setting, nothing to do" — so the one state
              that needs action looked like the one that didn't. Each state is
              stated as intent, and the actionable one carries its control. */}
          {permission !== null && (
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
              <span data-testid="push-permission" data-permission={permission}>
                {PUSH_PERMISSION_COPY[permission]}
              </span>
              {permission === "default" && (
                <Button
                  size="sm"
                  variant="outline"
                  className="h-6 px-2 text-xs"
                  disabled={disabled}
                  onClick={() => {
                    void enablePush().then((result) => {
                      setPermission(pushPermission());
                      if (result.ok) {
                        void push.persist({ pushNotificationsEnabled: true });
                      }
                    });
                  }}
                  data-testid="push-enable"
                >
                  Enable
                </Button>
              )}
            </p>
          )}
        </CardContent>
      </Card>
    </SettingsShell>
  );
};

export default NotificationsPage;
