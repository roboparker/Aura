import { useMemo, useState } from "react";
import { ChevronDown, ShieldCheck } from "lucide-react";
import {
  useAuth,
  type ImpersonationCategory,
  type ImpersonationLevel,
} from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import SaveIndicator from "@/components/settings/SaveIndicator";

const LEVELS: { value: ImpersonationLevel; label: string }[] = [
  { value: "hidden", label: "Hidden" },
  { value: "view", label: "View" },
  { value: "edit", label: "Edit" },
];

const ALL_CATEGORIES: ImpersonationCategory[] = [
  "tasks",
  "projects",
  "pages",
  "discussions",
  "comments",
  "notifications",
  "files",
];

// The five content categories roll up under one "Content" parent for a
// calmer UX; notifications and files stand on their own.
const CONTENT_CHILDREN: ImpersonationCategory[] = [
  "tasks",
  "projects",
  "pages",
  "discussions",
  "comments",
];

const CATEGORY_LABELS: Record<ImpersonationCategory, string> = {
  tasks: "Tasks",
  projects: "Projects",
  pages: "Pages",
  discussions: "Discussions",
  comments: "Comments",
  notifications: "Notifications",
  files: "Files",
};

const DEFAULT_ACCESS: Record<ImpersonationCategory, ImpersonationLevel> = {
  tasks: "hidden",
  projects: "hidden",
  pages: "hidden",
  discussions: "hidden",
  comments: "hidden",
  notifications: "hidden",
  files: "hidden",
};

/**
 * Three-way segmented control for a single category (or a parent rollup).
 * `value` may be "mixed" for a parent whose children disagree — in that
 * case no segment is highlighted until the user picks one.
 */
const LevelSelect = ({
  value,
  onChange,
  ariaLabel,
}: {
  value: ImpersonationLevel | "mixed";
  onChange: (level: ImpersonationLevel) => void;
  ariaLabel: string;
}) => (
  <div
    role="group"
    aria-label={ariaLabel}
    className="inline-flex shrink-0 gap-0.5 rounded-md border p-0.5"
  >
    {LEVELS.map((lvl) => {
      const active = value === lvl.value;
      return (
        <button
          key={lvl.value}
          type="button"
          aria-pressed={active}
          onClick={() => onChange(lvl.value)}
          className={cn(
            "rounded px-2.5 py-1 text-xs font-medium transition-colors",
            active
              ? "bg-primary text-primary-foreground"
              : "text-muted-foreground hover:bg-accent",
          )}
        >
          {lvl.label}
        </button>
      );
    })}
  </div>
);

const aggregate = (
  access: Record<ImpersonationCategory, ImpersonationLevel>,
  categories: ImpersonationCategory[],
): ImpersonationLevel | "mixed" => {
  const first = access[categories[0]];
  return categories.every((c) => access[c] === first) ? first : "mixed";
};

/**
 * Privacy control: whether platform admins may impersonate this account
 * (the firewall's switch_user feature) and, if so, what they may see/do per
 * content category. Off by default; every category defaults to "Hidden".
 * Enforced server-side by ImpersonationVoter + ImpersonationAccessListener —
 * these toggles just drive the stored preferences.
 */
const ImpersonationConsent = () => {
  const { user } = useAuth();
  const { persist, saveStatus, saveError } = usePreferencePersist();
  const [contentOpen, setContentOpen] = useState(false);

  const enabled = user?.preferences?.canBeImpersonated ?? false;
  const access = useMemo(
    () => ({ ...DEFAULT_ACCESS, ...(user?.preferences?.impersonationAccess ?? {}) }),
    [user?.preferences?.impersonationAccess],
  );

  const setLevels = (categories: ImpersonationCategory[], level: ImpersonationLevel) => {
    const next = { ...access };
    for (const c of categories) next[c] = level;
    void persist({ impersonationAccess: next });
  };

  const allAgg = aggregate(access, ALL_CATEGORIES);
  const contentAgg = aggregate(access, CONTENT_CHILDREN);

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
        <div className="space-y-1 rounded-md border p-3">
          <div className="mb-1 flex items-center justify-between gap-4 pb-1">
            <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
              What admins can access
            </p>
            <LevelSelect
              value={allAgg}
              onChange={(level) => setLevels(ALL_CATEGORIES, level)}
              ariaLabel="Set access for all categories"
            />
          </div>

          {/* Content parent + its children. */}
          <div className="rounded-md">
            <div className="flex items-center justify-between gap-4 py-1.5">
              <button
                type="button"
                onClick={() => setContentOpen((o) => !o)}
                className="flex items-center gap-1.5 text-sm font-medium"
                aria-expanded={contentOpen}
              >
                <ChevronDown
                  className={cn(
                    "h-4 w-4 text-muted-foreground transition-transform",
                    contentOpen ? "" : "-rotate-90",
                  )}
                  aria-hidden
                />
                Content
                <span className="text-xs font-normal text-muted-foreground">
                  (tasks, projects, pages, discussions, comments)
                </span>
              </button>
              <LevelSelect
                value={contentAgg}
                onChange={(level) => setLevels(CONTENT_CHILDREN, level)}
                ariaLabel="Set access for all content"
              />
            </div>
            {contentOpen ? (
              <div className="ml-6 space-y-1 border-l pl-3">
                {CONTENT_CHILDREN.map((cat) => (
                  <div
                    key={cat}
                    className="flex items-center justify-between gap-4 py-1"
                  >
                    <span className="text-sm">{CATEGORY_LABELS[cat]}</span>
                    <LevelSelect
                      value={access[cat]}
                      onChange={(level) => setLevels([cat], level)}
                      ariaLabel={`Set access for ${CATEGORY_LABELS[cat]}`}
                    />
                  </div>
                ))}
              </div>
            ) : null}
          </div>

          {/* Standalone sensitive categories. */}
          {(["notifications", "files"] as ImpersonationCategory[]).map((cat) => (
            <div
              key={cat}
              className="flex items-center justify-between gap-4 py-1.5"
            >
              <span className="text-sm font-medium">{CATEGORY_LABELS[cat]}</span>
              <LevelSelect
                value={access[cat]}
                onChange={(level) => setLevels([cat], level)}
                ariaLabel={`Set access for ${CATEGORY_LABELS[cat]}`}
              />
            </div>
          ))}

          <p className="pt-2 text-xs text-muted-foreground">
            Hidden = can&apos;t see it · View = read-only · Edit = read and
            change. Account security actions (password, two-factor, deletion)
            can never be performed while impersonating.
          </p>
        </div>
      ) : null}

      {saveStatus === "error" && saveError ? (
        <p className="text-sm text-destructive">{saveError}</p>
      ) : null}
    </div>
  );
};

export default ImpersonationConsent;
