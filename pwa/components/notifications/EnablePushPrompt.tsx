import { useState } from "react";
import { Bell } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import { enablePush, isPushSupported, pushPermission } from "@/lib/push";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

/**
 * Inline nudge to turn on browser (Web Push) notifications — shown where it's
 * worth prompting, e.g. when setting up reminders. Renders nothing if push is
 * unsupported, no VAPID key is configured, or the user already has it on. On
 * "Enable" it subscribes (lib/push) and flips the pushNotificationsEnabled
 * preference so the backend starts delivering.
 */
const EnablePushPrompt = ({ className }: { className?: string }) => {
  const { user } = useAuth();
  const { persist } = usePreferencePersist();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [dismissed, setDismissed] = useState(false);

  const supported = isPushSupported();
  const hasVapid = Boolean(process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY);
  const permission = pushPermission();
  const alreadyOn =
    permission === "granted" &&
    (user?.preferences?.pushNotificationsEnabled ?? false);

  if (!supported || !hasVapid || alreadyOn || dismissed) return null;

  const blocked = permission === "denied";

  const handleEnable = async () => {
    setBusy(true);
    setError(null);
    const result = await enablePush();
    if (result.ok) {
      await persist({ pushNotificationsEnabled: true });
      setDismissed(true);
    } else if (result.reason === "denied") {
      setError(
        "Notifications are blocked. Allow them in your browser settings, then reload.",
      );
    } else {
      setError("Couldn't enable browser notifications. Try again.");
    }
    setBusy(false);
  };

  return (
    <div
      className={cn(
        "rounded-md border border-input bg-muted/40 p-3 text-sm",
        className,
      )}
      data-testid="enable-push-prompt"
    >
      <div className="flex items-start gap-2">
        <Bell className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
        <div className="min-w-0 flex-1 space-y-2">
          <p>
            Turn on browser notifications to get reminders even when this tab is
            closed.
          </p>
          {blocked ? (
            <p className="text-xs text-muted-foreground">
              Notifications are blocked for this site — allow them in your
              browser settings, then reload.
            </p>
          ) : (
            <div className="flex items-center gap-2">
              <Button
                type="button"
                size="sm"
                onClick={() => void handleEnable()}
                disabled={busy}
                data-testid="enable-push"
              >
                {busy ? "Enabling…" : "Enable notifications"}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() => setDismissed(true)}
              >
                Not now
              </Button>
            </div>
          )}
          {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
      </div>
    </div>
  );
};

export default EnablePushPrompt;
