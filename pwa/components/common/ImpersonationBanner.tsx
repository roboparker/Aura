import { useRouter } from "next/router";
import { VenetianMask } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { displayName } from "@/lib/userDisplay";
import { Button } from "@/components/ui/button";

/**
 * Full-width bar shown above the sticky top bar whenever an admin is
 * impersonating another user (the firewall's switch_user). Keeps the operator
 * aware they're acting as someone else and offers a one-click way back to their
 * own session. The top-bar account menu carries the same "Stop impersonation"
 * control, so the exit stays reachable once this bar scrolls out of view.
 *
 * Renders nothing in the normal case, so it costs no layout when absent.
 */
const ImpersonationBanner = () => {
  const { user, stopImpersonation } = useAuth();
  const router = useRouter();

  const impersonator = user?.impersonator;
  if (!user || !impersonator) return null;

  const handleStop = () => {
    void stopImpersonation().then(() => router.push("/"));
  };

  return (
    <div
      className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b border-amber-300 bg-amber-100 px-4 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
      role="status"
      data-testid="impersonation-banner"
    >
      <span className="flex items-center gap-1.5">
        <VenetianMask className="h-4 w-4 shrink-0" />
        Impersonating <span className="font-semibold">{displayName(user)}</span>
        <span className="hidden text-amber-700 dark:text-amber-300 sm:inline">
          ({user.email})
        </span>
      </span>
      <Button
        variant="outline"
        size="sm"
        onClick={handleStop}
        className="h-7 border-amber-400 bg-amber-50 text-amber-900 hover:bg-amber-200 dark:border-amber-700 dark:bg-amber-900 dark:text-amber-100"
        data-testid="impersonation-banner-stop"
      >
        Stop impersonation
      </Button>
    </div>
  );
};

export default ImpersonationBanner;
