import { UserPlus } from "lucide-react";
import { cn } from "@/lib/utils";

type Size = "xs" | "sm";

/** Box sizes mirror the assignee avatars they sit beside, and use the SAME
 *  inline width/height mechanism as {@see UserAvatar} (which sizes via a px
 *  style, not a Tailwind class) so the dashed square is pixel-identical to a
 *  real avatar regardless of any surrounding flex/grid constraints. `sm` =
 *  UserAvatar size="sm" (32px); `xs` = the 16px list chips. */
const BOX_PX: Record<Size, number> = { xs: 16, sm: 32 };
const ICON: Record<Size, string> = { xs: "h-3 w-3", sm: "h-4 w-4" };

/**
 * Anonymous "no one assigned" placeholder avatar — a dashed square with a
 * UserPlus glyph, sized to match an actual assignee avatar. Shared by the board
 * cards, calendar chips, and the list assignee field so the empty-assignee
 * affordance looks the same everywhere.
 */
const AssigneePlaceholder = ({
  size = "sm",
  className,
}: {
  size?: Size;
  className?: string;
}) => {
  const px = BOX_PX[size];
  return (
    <span
      className={cn(
        "inline-flex shrink-0 items-center justify-center rounded-md border border-dashed border-muted-foreground/40 text-muted-foreground transition",
        className,
      )}
      style={{ width: px, height: px }}
      aria-hidden="true"
    >
      <UserPlus className={ICON[size]} />
    </span>
  );
};

export default AssigneePlaceholder;
