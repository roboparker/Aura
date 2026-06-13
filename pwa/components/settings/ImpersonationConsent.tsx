import { useMemo } from "react";
import { ShieldCheck } from "lucide-react";
import {
  useAuth,
  type ImpersonationCategory,
  type ImpersonationLevel,
} from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import SaveIndicator from "@/components/settings/SaveIndicator";
import ImpersonationItemExceptions from "@/components/settings/ImpersonationItemExceptions";
import PermissionTree, {
  type PermissionLevel,
  type PermissionNode,
} from "@/components/common/PermissionTree";

const LEVELS: PermissionLevel[] = [
  { value: "none", label: "None" },
  { value: "view", label: "View" },
  { value: "edit", label: "Edit" },
];

// The five content categories roll up under one "Content" group; the more
// sensitive notifications + files stand on their own as top-level leaves.
const NODES: PermissionNode[] = [
  {
    key: "content",
    label: "Content",
    description: "tasks, projects, pages, discussions, comments",
    children: [
      { key: "tasks", label: "Tasks" },
      { key: "projects", label: "Projects" },
      { key: "pages", label: "Pages" },
      { key: "discussions", label: "Discussions" },
      { key: "comments", label: "Comments" },
    ],
  },
  { key: "notifications", label: "Notifications" },
  { key: "files", label: "Files" },
];

const DEFAULT_ACCESS: Record<ImpersonationCategory, ImpersonationLevel> = {
  tasks: "none",
  projects: "none",
  pages: "none",
  discussions: "none",
  comments: "none",
  notifications: "none",
  files: "none",
};

/**
 * Privacy control: whether platform admins may impersonate this account
 * (the firewall's switch_user feature) and, if so, what they may see/do per
 * content category. Off by default; every category defaults to "None".
 * Enforced server-side by ImpersonationVoter + ImpersonationAccessListener —
 * the matrix UI just drives the stored preferences via the reusable
 * {@link PermissionTree}.
 */
const ImpersonationConsent = () => {
  const { user } = useAuth();
  const { persist, saveStatus, saveError } = usePreferencePersist();

  const enabled = user?.preferences?.canBeImpersonated ?? false;
  const access = useMemo(
    () => ({ ...DEFAULT_ACCESS, ...(user?.preferences?.impersonationAccess ?? {}) }),
    [user?.preferences?.impersonationAccess],
  );

  return (
    <div className="space-y-4">
      {/* Master toggle. */}
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
            issues — limited to exactly what you grant below. Off by default;
            leave it off and no one can impersonate your account.
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

      {enabled ? (
        <div className="rounded-md border p-3">
          <PermissionTree
            levels={LEVELS}
            nodes={NODES}
            values={access as Record<string, string>}
            masterLabel="What admins can access"
            onChange={(next) =>
              void persist({
                impersonationAccess: next as Record<
                  ImpersonationCategory,
                  ImpersonationLevel
                >,
              })
            }
          />
          <p className="pt-2 text-xs text-muted-foreground">
            None = can&apos;t see it · View = read-only · Edit = read and
            change. Account security actions (password, two-factor, deletion)
            can never be performed while impersonating.
          </p>

          <div className="mt-3 border-t pt-3">
            <ImpersonationItemExceptions />
          </div>
        </div>
      ) : null}

      {saveStatus === "error" && saveError ? (
        <p className="text-sm text-destructive">{saveError}</p>
      ) : null}
    </div>
  );
};

export default ImpersonationConsent;
