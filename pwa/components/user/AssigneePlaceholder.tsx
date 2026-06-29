import { UserPlus } from "lucide-react";
import { cn } from "@/lib/utils";

type Size = "xs" | "sm";

/** Box sizes mirror the assignee avatars they sit beside: `sm` = UserAvatar
 *  size="sm" (32px) on board/calendar cards; `xs` = the 16px list chips. */
const BOX: Record<Size, string> = { xs: "h-4 w-4", sm: "h-8 w-8" };
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
}) => (
  <span
    className={cn(
      "flex shrink-0 items-center justify-center rounded-md border border-dashed border-muted-foreground/40 text-muted-foreground transition",
      BOX[size],
      className,
    )}
    aria-hidden="true"
  >
    <UserPlus className={ICON[size]} />
  </span>
);

export default AssigneePlaceholder;
