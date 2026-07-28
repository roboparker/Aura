import { AVATAR_PALETTE, colorName } from "@/lib/avatarPalette";
import { cn } from "@/lib/utils";

/**
 * Flat row of round color swatches — the picker shape used in the
 * account settings for avatar color. Reused on the create-space and
 * edit-space surfaces so the same affordance picks the same kind of
 * value across the app.
 *
 * Stateless: the caller owns the selected value and is notified on
 * click. `disabled` short-circuits the radio role and the click
 * handler — pass a string when an async save is in flight so the
 * surrounding form can stop the user from racing themselves.
 */
interface Props {
  value: string;
  onChange: (color: string) => void;
  /** Color palette to render. Defaults to {@link AVATAR_PALETTE}. */
  options?: readonly string[];
  /** Highlights the in-flight swatch with a pulse during async save. */
  savingColor?: string | null;
  /** Disable all interactions (e.g. while parent is saving). */
  disabled?: boolean;
  /** Accessible label for the radiogroup. */
  ariaLabel?: string;
  className?: string;
}

const ColorSwatchPicker = ({
  value,
  onChange,
  options = AVATAR_PALETTE,
  savingColor = null,
  disabled = false,
  ariaLabel,
  className,
}: Props) => {
  return (
    <div
      role="radiogroup"
      aria-label={ariaLabel}
      className={cn("grid w-fit grid-cols-8 gap-2", className)}
    >
      {options.map((color) => {
        const isSelected = value === color;
        const isSaving = savingColor === color;
        return (
          <button
            key={color}
            type="button"
            role="radio"
            aria-checked={isSelected}
            // Named, not hex: the rest of this picker is wired correctly, so
            // the label was the only thing making it unusable without sight.
            aria-label={colorName(color)}
            disabled={disabled}
            onClick={() => onChange(color)}
            className={cn(
              "h-8 w-8 rounded-md transition focus:outline-none focus:ring-2 focus:ring-ring disabled:cursor-not-allowed",
              isSelected
                ? "ring-2 ring-ring scale-110"
                : "hover:scale-105",
              isSaving && "animate-pulse",
            )}
            style={{ backgroundColor: color }}
          />
        );
      })}
    </div>
  );
};

export default ColorSwatchPicker;
