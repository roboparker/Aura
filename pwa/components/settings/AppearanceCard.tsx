import { Monitor, Moon, Sun } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import type { ThemePreference } from "@/contexts/AuthContext";
import { cn } from "@/lib/utils";

const THEME_OPTIONS: Array<{
  value: ThemePreference;
  label: string;
  Icon: typeof Sun;
}> = [
  { value: "light", label: "Light", Icon: Sun },
  { value: "dark", label: "Dark", Icon: Moon },
  { value: "system", label: "System", Icon: Monitor },
];

/**
 * Theme picker card. Controlled — the parent persists `theme` to
 * preferences (AuthContext applies it via next-themes on change).
 */
interface AppearanceCardProps {
  value: ThemePreference;
  onChange: (theme: ThemePreference) => void;
}

const AppearanceCard = ({ value, onChange }: AppearanceCardProps) => (
  <Card data-testid="settings-appearance">
    <CardHeader>
      <CardTitle>Appearance</CardTitle>
    </CardHeader>
    <CardContent>
      <Label className="text-sm font-medium">Theme</Label>
      <p className="mb-2 text-xs text-muted-foreground">
        Pick a theme or follow your operating system.
      </p>
      <div className="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Theme">
        {THEME_OPTIONS.map(({ value: option, label, Icon }) => {
          const selected = value === option;
          return (
            <button
              key={option}
              type="button"
              role="radio"
              aria-checked={selected}
              onClick={() => onChange(option)}
              className={cn(
                "flex flex-col items-center gap-1 rounded-md border px-3 py-3 text-sm transition-colors",
                selected
                  ? "border-primary bg-primary/10 text-foreground"
                  : "border-input hover:bg-accent",
              )}
              data-testid={`settings-theme-${option}`}
            >
              <Icon className="h-4 w-4" />
              <span>{label}</span>
            </button>
          );
        })}
      </div>
    </CardContent>
  </Card>
);

export default AppearanceCard;
