import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useRef, useState } from "react";
import { Check, Loader2, Monitor, Moon, Sun } from "lucide-react";
import { useAuth, type UserPreferences } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import { cn } from "@/lib/utils";

const THEME_OPTIONS: Array<{
  value: UserPreferences["theme"];
  label: string;
  Icon: typeof Sun;
}> = [
  { value: "light", label: "Light", Icon: Sun },
  { value: "dark", label: "Dark", Icon: Moon },
  { value: "system", label: "System", Icon: Monitor },
];

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

type SaveStatus = "idle" | "saving" | "saved" | "error";

const Settings = () => {
  const { user, isAuthenticated, isLoading, updateUserLocally } = useAuth();
  const router = useRouter();
  const [saveStatus, setSaveStatus] = useState<SaveStatus>("idle");
  const [saveError, setSaveError] = useState<string | null>(null);
  // Each in-flight PATCH increments this so a slow-then-fast pair doesn't
  // overwrite the newer state with the older response.
  const pendingRequestId = useRef(0);
  const savedToastTimer = useRef<number | null>(null);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  useEffect(() => {
    return () => {
      if (savedToastTimer.current !== null) {
        window.clearTimeout(savedToastTimer.current);
      }
    };
  }, []);

  const persist = useCallback(
    async (patch: Partial<UserPreferences>) => {
      if (!user) return;
      // Optimistic merge: surface the change instantly. The server response
      // (or rollback below) becomes the source of truth.
      const previous = user.preferences;
      const next = { ...previous, ...patch };
      updateUserLocally({ preferences: next });
      setSaveStatus("saving");
      setSaveError(null);

      const requestId = ++pendingRequestId.current;
      try {
        const res = await fetch(`${ENTRYPOINT}/me/preferences`, {
          method: "PATCH",
          credentials: "include",
          headers: { "Content-Type": "application/merge-patch+json" },
          body: JSON.stringify(patch),
        });
        if (!res.ok) {
          const data = await res.json().catch(() => ({}));
          throw new Error(data.error || "Failed to save preferences.");
        }
        // Drop the response if a newer request has already fired so we don't
        // clobber the user's latest change with a stale snapshot.
        if (requestId !== pendingRequestId.current) return;
        const body = (await res.json()) as UserPreferences;
        updateUserLocally({ preferences: body });
        setSaveStatus("saved");
        if (savedToastTimer.current !== null) {
          window.clearTimeout(savedToastTimer.current);
        }
        savedToastTimer.current = window.setTimeout(
          () => setSaveStatus("idle"),
          1500,
        );
      } catch (err) {
        if (requestId !== pendingRequestId.current) return;
        updateUserLocally({ preferences: previous });
        setSaveStatus("error");
        setSaveError(
          err instanceof Error ? err.message : "Failed to save preferences.",
        );
      }
    },
    [user, updateUserLocally],
  );

  if (isLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading...</p>
      </div>
    );
  }

  const prefs = user.preferences;
  const pushPermission =
    typeof window !== "undefined" && "Notification" in window
      ? Notification.permission
      : "default";

  return (
    <>
      <Head>
        <title>Settings - Aura</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto space-y-6">
          <header className="flex items-center justify-between gap-3">
            <div>
              <h1 className="text-2xl font-bold">Settings</h1>
              <p className="text-sm text-muted-foreground">
                Theme and notification preferences for your account.
              </p>
            </div>
            <SaveIndicator status={saveStatus} />
          </header>

          {saveError && (
            <Alert variant="destructive" data-testid="settings-error">
              <AlertDescription>{saveError}</AlertDescription>
            </Alert>
          )}

          <Card data-testid="settings-appearance">
            <CardHeader>
              <CardTitle>Appearance</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div>
                <Label className="text-sm font-medium">Theme</Label>
                <p className="text-xs text-muted-foreground mb-2">
                  Pick a theme or follow your operating system.
                </p>
                <div
                  className="grid grid-cols-3 gap-2"
                  role="radiogroup"
                  aria-label="Theme"
                >
                  {THEME_OPTIONS.map(({ value, label, Icon }) => {
                    const selected = prefs.theme === value;
                    return (
                      <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => persist({ theme: value })}
                        className={cn(
                          "flex flex-col items-center gap-1 rounded-md border px-3 py-3 text-sm transition-colors",
                          selected
                            ? "border-primary bg-primary/10 text-foreground"
                            : "border-input hover:bg-accent",
                        )}
                        data-testid={`settings-theme-${value}`}
                      >
                        <Icon className="h-4 w-4" />
                        <span>{label}</span>
                      </button>
                    );
                  })}
                </div>
              </div>
            </CardContent>
          </Card>

          <Card data-testid="settings-notifications">
            <CardHeader>
              <CardTitle>Notifications</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
              <ToggleRow
                id="email-toggle"
                label="Email notifications"
                description="Receive task reminders via email."
                checked={prefs.emailNotificationsEnabled}
                onChange={(checked) =>
                  persist({ emailNotificationsEnabled: checked })
                }
                testId="settings-email-toggle"
              />

              <Separator />

              <div className="space-y-2">
                <ToggleRow
                  id="push-toggle"
                  label="Push notifications"
                  description="Send browser push notifications when reminders fire."
                  checked={prefs.pushNotificationsEnabled}
                  onChange={(checked) =>
                    persist({ pushNotificationsEnabled: checked })
                  }
                  testId="settings-push-toggle"
                />
                <p className="text-xs text-muted-foreground">
                  Browser permission:{" "}
                  <span className="font-medium" data-testid="push-permission">
                    {pushPermission}
                  </span>
                  . Push delivery isn’t wired up yet — your preference is
                  saved and will take effect once browser push lands.
                </p>
              </div>

              <Separator />

              <div>
                <Label className="text-sm font-medium">Frequency</Label>
                <p className="text-xs text-muted-foreground mb-2">
                  How often you’d like reminder emails grouped.
                </p>
                <div
                  className="space-y-2"
                  role="radiogroup"
                  aria-label="Notification frequency"
                >
                  {FREQUENCY_OPTIONS.map(({ value, label, description }) => {
                    const selected = prefs.notificationFrequency === value;
                    return (
                      <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() =>
                          persist({ notificationFrequency: value })
                        }
                        className={cn(
                          "w-full text-left rounded-md border px-3 py-2 text-sm transition-colors",
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

          <p className="text-xs text-muted-foreground text-center">
            Changes save automatically.
          </p>
        </div>
      </div>
    </>
  );
};

interface ToggleRowProps {
  id: string;
  label: string;
  description: string;
  checked: boolean;
  onChange: (next: boolean) => void;
  testId: string;
}

const ToggleRow = ({
  id,
  label,
  description,
  checked,
  onChange,
  testId,
}: ToggleRowProps) => (
  <div className="flex items-start justify-between gap-4">
    <div className="flex-1 min-w-0">
      <Label htmlFor={id} className="text-sm font-medium cursor-pointer">
        {label}
      </Label>
      <p className="text-xs text-muted-foreground">{description}</p>
    </div>
    <input
      id={id}
      type="checkbox"
      role="switch"
      checked={checked}
      onChange={(e) => onChange(e.target.checked)}
      className="mt-1 h-4 w-4 cursor-pointer"
      data-testid={testId}
    />
  </div>
);

interface SaveIndicatorProps {
  status: SaveStatus;
}

// Tiny passive status pill — appears for the saving/saved transitions and
// hides when idle, so the page doesn't grow a permanent "Saved ✓" sticker.
const SaveIndicator = ({ status }: SaveIndicatorProps) => {
  if (status === "idle" || status === "error") return null;
  return (
    <Button
      variant="outline"
      size="sm"
      type="button"
      disabled
      className="pointer-events-none gap-1.5"
      data-testid={`settings-save-${status}`}
    >
      {status === "saving" ? (
        <>
          <Loader2 className="h-3.5 w-3.5 animate-spin" />
          Saving…
        </>
      ) : (
        <>
          <Check className="h-3.5 w-3.5" />
          Saved
        </>
      )}
    </Button>
  );
};

export default Settings;
