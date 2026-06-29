import { UserPlus } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Anonymous "no one assigned" placeholder avatar — a dashed square with a
 * UserPlus glyph. Shared by the board cards and the list assignee field so the
 * empty-assignee affordance looks the same everywhere.
 */
const AssigneePlaceholder = ({ className }: { className?: string }) => (
  <span
    className={cn(
      "flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-dashed border-muted-foreground/40 text-muted-foreground transition",
      className,
    )}
    aria-hidden="true"
  >
    <UserPlus className="h-3.5 w-3.5" />
  </span>
);

export default AssigneePlaceholder;
