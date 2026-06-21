import { useEffect, useState } from "react";
import { TriangleAlert } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import { enablePush, isPushSupported, pushPermission } from "@/lib/push";
import { cn } from "@/lib/utils";

/**
 * Small warning shown where reminders are configured but browser (Web Push)
 * notifications aren't on — so the user knows their reminders won't reach the
 * browser. Offers an inline "Turn on" when push is actually available (supported
 * + a VAPID key is configured + not blocked); otherwise it's informational.
 * Renders nothing once notifications are on, or when push isn't supported.
 */
const EnablePushPrompt = ({ className }: { className?: string }) => {
  const { user } = useAuth();
  const { persist } = usePreferencePersist();
  const [mounted, setMounted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [dismissed, setDismissed] = useState(false);

  // Permission/support are client-only; gate on mount to avoid an SSR mismatch.
  useEffect(() => setMounted(true), []);

  const supported = isPushSupported();
  const permission = pushPermission();
  const hasVapid = Boolean(process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY);
  const alreadyOn =
    permission === "granted" &&
    (user?.preferences?.pushNotificationsEnabled ?? false);

  if (!mounted || !supported || alreadyOn || dismissed) return null;

  const canEnable = hasVapid && permission !== "denied";

  const handleEnable = async () => {
    setBusy(true);
    setError(null);
    const result = await enablePush();
    if (result.ok) {
      await persist({ pushNotificationsEnabled: true });
      setDismissed(true);
    } else {
      setError(
        result.reason === "denied"
          ? "Allow notifications in your browser settings, then reload."
          : "Couldn't enable browser notifications.",
      );
    }
    setBusy(false);
  };

  return (
    <p
      className={cn(
        "flex flex-wrap items-center gap-1.5 text-xs text-amber-600 dark:text-amber-500",
        className,
      )}
      data-testid="enable-push-prompt"
    >
      <TriangleAlert className="h-3.5 w-3.5 shrink-0" aria-hidden />
      <span>
        Browser notifications are off — you won&apos;t get these reminders.
      </span>
      {canEnable && (
        <button
          type="button"
          onClick={() => void handleEnable()}
          disabled={busy}
          className="font-medium underline underline-offset-2 hover:no-underline disabled:opacity-60"
          data-testid="enable-push"
        >
          {busy ? "Enabling…" : "Turn on"}
        </button>
      )}
      {error && <span className="text-destructive">{error}</span>}
    </p>
  );
};

export default EnablePushPrompt;
