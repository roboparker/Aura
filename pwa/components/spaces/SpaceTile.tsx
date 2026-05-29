import { Lock } from "lucide-react";
import { deterministicPaletteColor } from "@/lib/avatarPalette";
import { cn } from "@/lib/utils";

type Size = "sm" | "md" | "lg";

const SIZE_PX: Record<Size, number> = { sm: 32, md: 48, lg: 64 };
const SIZE_TEXT: Record<Size, string> = {
  sm: "text-xs",
  md: "text-lg",
  lg: "text-2xl",
};
const ICON_SIZE: Record<Size, string> = {
  sm: "h-3.5 w-3.5",
  md: "h-5 w-5",
  lg: "h-7 w-7",
};

/**
 * Square colored tile for a Space, mirroring the look of UserAvatar
 * but rounded instead of circular and keyed off the space (name + id)
 * via the deterministic palette so the same space always gets the
 * same color.
 *
 * Personal spaces render the same colored letter tile as shared
 * spaces, with a dark overlay + Lock icon stacked on top so they're
 * still recognisable as "owned by you, nobody else can see this."
 */
interface Props {
  name: string;
  /** Stable seed for color pick — pass the space `id` so the swatch
   *  survives renames. */
  seed: string;
  isPersonal: boolean;
  size?: Size;
  className?: string;
}

const SpaceTile = ({ name, seed, isPersonal, size = "md", className }: Props) => {
  const px = SIZE_PX[size];
  const initial = name.trim().charAt(0).toUpperCase() || "?";

  return (
    <span
      aria-hidden
      className={cn(
        "relative rounded-md inline-flex items-center justify-center shrink-0 overflow-hidden",
        "leading-none font-semibold text-white",
        SIZE_TEXT[size],
        className,
      )}
      style={{
        backgroundColor: deterministicPaletteColor(seed),
        width: px,
        height: px,
      }}
    >
      {initial}
      {isPersonal && (
        <span className="absolute inset-0 inline-flex items-center justify-center bg-black/55">
          <Lock className={cn(ICON_SIZE[size], "text-white")} />
        </span>
      )}
    </span>
  );
};

export default SpaceTile;
