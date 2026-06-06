import Link from "next/link";
import { Bell, KeyRound, Shield, TriangleAlert, User } from "lucide-react";
import { cn } from "@/lib/utils";

export type SettingsSectionKey =
  | "profile"
  | "security"
  | "notifications"
  | "api-tokens"
  | "danger";

export interface SettingsSection {
  key: SettingsSectionKey;
  label: string;
  href: string;
  Icon: typeof User;
  danger?: boolean;
}

/**
 * The Settings subnav sections. Later phases append their own entries as
 * they land (API tokens, Danger zone) so the nav never links to an empty
 * panel.
 */
export const SETTINGS_SECTIONS: SettingsSection[] = [
  { key: "profile", label: "Profile", href: "/settings/profile", Icon: User },
  { key: "security", label: "Security", href: "/settings/security", Icon: Shield },
  {
    key: "notifications",
    label: "Notifications",
    href: "/settings/notifications",
    Icon: Bell,
  },
  {
    key: "api-tokens",
    label: "API tokens",
    href: "/settings/api-tokens",
    Icon: KeyRound,
  },
  {
    key: "danger",
    label: "Danger zone",
    href: "/settings/danger",
    Icon: TriangleAlert,
    danger: true,
  },
];

interface SettingsNavProps {
  active: SettingsSectionKey;
}

/**
 * Vertical subnav on desktop; horizontally scrollable pill row on mobile.
 */
const SettingsNav = ({ active }: SettingsNavProps) => (
  <nav
    aria-label="Settings sections"
    className="flex gap-1 overflow-x-auto pb-1 md:flex-col md:overflow-visible md:pb-0"
    data-testid="settings-nav"
  >
    {SETTINGS_SECTIONS.map(({ key, label, href, Icon, danger }) => {
      const isActive = key === active;
      return (
        <Link
          key={key}
          href={href}
          aria-current={isActive ? "page" : undefined}
          data-testid={`settings-nav-${key}`}
          className={cn(
            "flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors",
            isActive
              ? "bg-accent text-accent-foreground"
              : "text-muted-foreground hover:bg-accent/50 hover:text-foreground",
            danger && !isActive && "text-destructive/80 hover:text-destructive",
            danger && isActive && "text-destructive",
          )}
        >
          <Icon className="h-4 w-4 shrink-0" />
          {label}
        </Link>
      );
    })}
  </nav>
);

export default SettingsNav;
