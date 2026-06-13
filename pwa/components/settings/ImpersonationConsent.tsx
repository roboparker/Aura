import { ShieldCheck } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import SaveIndicator from "@/components/settings/SaveIndicator";

/**
 * Privacy control letting a user decide whether platform admins may
 * impersonate their account (the firewall's switch_user feature). Off by
 * default and enforced server-side by App\Security\ImpersonationVoter — this
 * toggle just drives the stored `canBeImpersonated` preference.
 */
const ImpersonationConsent = () => {
  const { user } = useAuth();
  const { persist, saveStatus, saveError } = usePreferencePersist();

  const enabled = user?.preferences?.canBeImpersonated ?? false;

  return (
    <div className="space-y-3">
      <div className="flex items-start justify-between gap-4">
        <div className="space-y-1">
          <Label
            htmlFor="impersonation-consent"
            className="flex items-center gap-2 text-sm font-medium"
          >
            <ShieldCheck className="h-4 w-4 text-muted-foreground" aria-hidden />
            Allow admin impersonation
          </Label>
          <p className="text-sm text-muted-foreground">
            When on, platform administrators can sign in as you to help debug
            issues. Off by default — leave it off and no one can impersonate
            your account, even an admin.
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2 pt-0.5">
          <SaveIndicator status={saveStatus} />
          <Switch
            id="impersonation-consent"
            checked={enabled}
            onCheckedChange={(checked) =>
              void persist({ canBeImpersonated: checked })
            }
            aria-label="Allow admin impersonation"
          />
        </div>
      </div>
      {saveStatus === "error" && saveError ? (
        <p className="text-sm text-destructive">{saveError}</p>
      ) : null}
    </div>
  );
};

export default ImpersonationConsent;
