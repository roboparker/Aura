import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { displayName } from "@/lib/userDisplay";
import { cn } from "@/lib/utils";

interface Props {
  /** Already-capped list of users to show. */
  users: AvatarUser[];
  /**
   * Total distinct participants. When greater than `users.length`, the
   * overflow renders as a "+N" chip after the avatars.
   */
  total?: number;
  size?: "sm" | "md";
  className?: string;
}

const RING = "ring-2 ring-background";

/**
 * Overlapping cluster of user avatars with a "+N" overflow chip — the
 * "who's in this thread" affordance on discussion rows and the detail
 * header. Presentation only; the caller decides who and how many.
 */
const AvatarStack = ({ users, total, size = "sm", className }: Props) => {
  if (users.length === 0) return null;

  const shown = total ?? users.length;
  const overflow = Math.max(0, shown - users.length);
  const chipSize = size === "sm" ? "h-8 w-8 text-[11px]" : "h-10 w-10 text-xs";

  return (
    <div
      className={cn("flex items-center -space-x-2", className)}
      data-testid="avatar-stack"
    >
      {users.map((u, i) => (
        <UserAvatar
          key={u.email ?? i}
          user={u}
          size={size}
          className={RING}
        />
      ))}
      {overflow > 0 && (
        <span
          className={cn(
            "inline-flex items-center justify-center rounded-full bg-muted font-medium text-muted-foreground select-none",
            RING,
            chipSize,
          )}
          title={`${overflow} more`}
        >
          +{overflow}
        </span>
      )}
      <span className="sr-only">
        {users.map((u) => displayName(u)).join(", ")}
        {overflow > 0 ? ` and ${overflow} more` : ""}
      </span>
    </div>
  );
};

export default AvatarStack;
