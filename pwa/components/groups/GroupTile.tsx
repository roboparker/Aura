import { LayoutGrid } from "lucide-react";
import { cn } from "@/lib/utils";

type Size = "sm" | "md" | "lg";

const SIZE_PX: Record<Size, number> = { sm: 32, md: 48, lg: 64 };
const ICON_SIZE: Record<Size, string> = {
  sm: "h-4 w-4",
  md: "h-6 w-6",
  lg: "h-8 w-8",
};

/**
 * Square colored tile for a UserGroup, mirroring {@link SpaceTile} but
 * stamped with the 2×2 grid glyph that reads as "a set of people" rather
 * than a single initial — the visual language the mockups use to tell
 * groups apart from spaces and users at a glance.
 *
 * Color is resolved by the caller (see `resolveGroupColor` in
 * `@/lib/avatarPalette`) so this stays a pure presentation component.
 */
interface Props {
  color: string;
  size?: Size;
  className?: string;
}

const GroupTile = ({ color, size = "md", className }: Props) => {
  const px = SIZE_PX[size];

  return (
    <span
      aria-hidden
      className={cn(
        "relative rounded-md inline-flex items-center justify-center shrink-0 text-white",
        className,
      )}
      style={{ backgroundColor: color, width: px, height: px }}
    >
      <LayoutGrid className={ICON_SIZE[size]} />
    </span>
  );
};

export default GroupTile;
