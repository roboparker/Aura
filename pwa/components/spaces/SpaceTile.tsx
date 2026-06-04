import { Lock } from "lucide-react";
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
 * but rounded instead of circular. Color is resolved by the caller
 * (see `resolveSpaceColor` in `@/lib/avatarPalette`) so this stays a
 * pure presentation component — same code path for personal and
 * shared spaces.
 *
 * Personal spaces stack a dark overlay + Lock icon on top of the
 * colored tile so they're still recognisable as "owned by you,
 * nobody else can see this."
 */
interface Props {
  name: string;
  color: string;
  isPersonal: boolean;
  size?: Size;
  className?: string;
}

const SpaceTile = ({ name, color, isPersonal, size = "md", className }: Props) => {
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
      style={{ backgroundColor: color, width: px, height: px }}
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
