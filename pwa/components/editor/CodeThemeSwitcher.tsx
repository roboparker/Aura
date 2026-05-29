import { useCallback, useEffect, useState } from "react";
import { Check, Palette } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  CODE_THEME_PRESETS,
  CODE_THEME_STORAGE_KEY,
  DEFAULT_CODE_THEME_ID,
  isKnownCodeThemeId,
} from "@/lib/codeThemes";

interface CodeThemeSwitcherProps {
  /**
   * `full` — labelled outline button (page-level use).
   * `compact` — icon-only trigger sized to sit inside the CodeFence
   * header bar next to the copy button.
   */
  variant?: "full" | "compact";
}

const readActive = (): string => {
  if (typeof document === "undefined") return DEFAULT_CODE_THEME_ID;
  const attr = document.documentElement.dataset.codeTheme;
  return isKnownCodeThemeId(attr) ? (attr as string) : DEFAULT_CODE_THEME_ID;
};

// Lets the reader pick which Prism palette renders every code fence on
// the page. The choice is applied by mutating `html[data-code-theme]`
// (the CSS rules in globals.css handle the rest) and persisted to
// localStorage so the inline bootstrap script in `_document.tsx` can
// restore it on the next page load.
const CodeThemeSwitcher = ({ variant = "full" }: CodeThemeSwitcherProps) => {
  const [active, setActive] = useState<string>(DEFAULT_CODE_THEME_ID);

  // Sync from the DOM after mount — the inline bootstrap script has
  // already stamped the saved value onto <html> by the time React
  // renders the first frame.
  useEffect(() => {
    setActive(readActive());
  }, []);

  const handlePick = useCallback((id: string) => {
    document.documentElement.setAttribute("data-code-theme", id);
    try {
      localStorage.setItem(CODE_THEME_STORAGE_KEY, id);
    } catch {
      // localStorage may be unavailable (private mode) — silently
      // accept the in-page change.
    }
    setActive(id);
  }, []);

  const activeLabel =
    CODE_THEME_PRESETS.find((p) => p.id === active)?.label ?? "Code theme";

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        {variant === "compact" ? (
          <button
            type="button"
            aria-label={`Code theme: ${activeLabel}`}
            title={`Code theme: ${activeLabel}`}
            className="inline-flex h-6 w-6 items-center justify-center rounded-sm text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
          >
            <Palette className="h-3.5 w-3.5" aria-hidden />
          </button>
        ) : (
          <Button variant="outline" size="sm" className="gap-2">
            <Palette className="h-4 w-4" aria-hidden />
            <span>{activeLabel}</span>
          </Button>
        )}
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-48">
        <DropdownMenuLabel>Code theme</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {CODE_THEME_PRESETS.map((preset) => (
          <DropdownMenuItem
            key={preset.id}
            onSelect={() => handlePick(preset.id)}
            className="flex items-center justify-between"
          >
            <span>{preset.label}</span>
            {preset.id === active ? (
              <Check className="h-4 w-4" aria-hidden />
            ) : null}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
};

export default CodeThemeSwitcher;
