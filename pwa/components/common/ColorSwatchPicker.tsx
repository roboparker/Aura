import { useEffect, useRef, type KeyboardEvent } from "react";
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
  const refs = useRef<Array<HTMLButtonElement | null>>([]);

  // A radiogroup is one tab stop, not one per option. Without a roving
  // tabindex these 16 swatches were 16 stops on the way past the picker, and
  // the arrow keys — the only way a radiogroup is meant to be operated — did
  // nothing. The selected swatch holds the stop; if the current value isn't in
  // the palette (nothing selected), the first one does, so the group is always
  // reachable.
  const selectedIndex = options.indexOf(value);
  const tabbableIndex = selectedIndex >= 0 ? selectedIndex : 0;

  // Where focus belongs once the pending render settles.
  //
  // Focusing inline doesn't survive: callers disable the whole group while the
  // change saves (AvatarSection passes `disabled={!!savingColor}`), and a
  // disabled button cannot hold focus — the browser drops it to <body>, which
  // ends keyboard traversal after a single arrow press. Recording the intent
  // and applying it from an effect means focus lands as soon as the group is
  // interactive again, whether that's this render or the one after the save.
  const pendingFocus = useRef<number | null>(null);

  useEffect(() => {
    const index = pendingFocus.current;
    if (index === null) return;
    const el = refs.current[index];
    if (el && !el.disabled) {
      el.focus();
      pendingFocus.current = null;
    }
    // Still disabled: stay pending. `disabled` is a dependency, so this reruns
    // the moment the save finishes.
  }, [disabled, value]);

  // Linear traversal with wrap, which is the radiogroup pattern regardless of
  // how the options are laid out visually. Deliberately not column-aware: the
  // grid is a `className` the caller can override, so inferring a row width
  // from it would be a guess that breaks the moment someone restyles it.
  const move = (to: number) => {
    pendingFocus.current = to;
    onChange(options[to]);
  };

  const onKeyDown = (event: KeyboardEvent, index: number) => {
    if (disabled) return;
    const jump: Record<string, number> = {
      ArrowRight: 1,
      ArrowDown: 1,
      ArrowLeft: -1,
      ArrowUp: -1,
    };
    if (event.key in jump) {
      // Arrow keys select as they move — that's what makes it a radiogroup
      // rather than a toolbar, and it's why the group only needs one stop.
      event.preventDefault();
      move((index + jump[event.key] + options.length) % options.length);
      return;
    }
    if (event.key === "Home" || event.key === "End") {
      event.preventDefault();
      move(event.key === "Home" ? 0 : options.length - 1);
    }
  };

  return (
    <div
      role="radiogroup"
      aria-label={ariaLabel}
      className={cn("grid w-fit grid-cols-8 gap-2", className)}
    >
      {options.map((color, index) => {
        const isSelected = value === color;
        const isSaving = savingColor === color;
        return (
          <button
            key={color}
            type="button"
            role="radio"
            ref={(el) => {
              refs.current[index] = el;
            }}
            aria-checked={isSelected}
            // Named, not hex: the rest of this picker is wired correctly, so
            // the label was the only thing making it unusable without sight.
            aria-label={colorName(color)}
            tabIndex={index === tabbableIndex ? 0 : -1}
            disabled={disabled}
            onClick={() => onChange(color)}
            onKeyDown={(e) => onKeyDown(e, index)}
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
