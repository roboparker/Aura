import { Check } from "lucide-react";
import { AVATAR_PALETTE } from "@/lib/avatarPalette";

interface Props {
  initials: string;
  selected: string;
  onChange: (color: string) => void;
}

/**
 * Sign-up step that lets the user pick the color their initials chip
 * shows everywhere in the app. Mirrors the WCAG-AA-checked palette in
 * `pwa/lib/avatarPalette.ts` (which itself mirrors
 * `App\Service\AvatarColorService::PALETTE`), so any swatch the user
 * picks is guaranteed to pass white-on-color contrast.
 *
 * Each swatch renders the user's initials as a preview, so they're
 * picking the actual end-state rather than an abstract color square.
 */
const AvatarColorPicker = ({ initials, selected, onChange }: Props) => {
  return (
    <div className="space-y-3" data-testid="avatar-color-picker">
      <div
        role="radiogroup"
        aria-label="Avatar color"
        className="grid grid-cols-6 gap-2"
      >
        {AVATAR_PALETTE.map((color) => {
          const isSelected = color === selected;
          return (
            <button
              key={color}
              type="button"
              role="radio"
              aria-checked={isSelected}
              aria-label={`Color ${color}`}
              onClick={() => onChange(color)}
              data-testid="avatar-color-swatch"
              data-selected={isSelected ? "true" : "false"}
              className={[
                "relative aspect-square rounded-md flex items-center justify-center text-sm font-semibold text-white",
                "transition-all outline-none",
                "focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background",
                isSelected
                  ? "ring-2 ring-primary ring-offset-2 ring-offset-background scale-105"
                  : "hover:scale-105",
              ].join(" ")}
              style={{ backgroundColor: color }}
            >
              <span aria-hidden>{initials}</span>
              {isSelected && (
                <Check
                  className="absolute -top-1 -right-1 h-4 w-4 rounded-full bg-primary text-primary-foreground p-0.5"
                  aria-hidden
                />
              )}
            </button>
          );
        })}
      </div>
      <p className="text-[10px] text-muted-foreground text-center">
        All swatches pass WCAG AA against white text.
      </p>
    </div>
  );
};

export default AvatarColorPicker;
