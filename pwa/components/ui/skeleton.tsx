import * as React from "react";

import { cn } from "@/lib/utils";

/**
 * A pulsing placeholder box, sized by the caller.
 *
 * Always `aria-hidden`: a skeleton is a picture of content that doesn't exist
 * yet, so there is nothing for a screen reader to usefully say about it — and
 * a list of eight of them would say it eight times. Announce the busy state
 * once, on the region (see `SkeletonList`), not on each shape.
 */
const Skeleton = ({ className, ...props }: React.ComponentProps<"div">) => (
  <div
    aria-hidden="true"
    className={cn("animate-pulse rounded-md bg-muted/60", className)}
    {...props}
  />
);

/**
 * The common case: N identically-shaped placeholders standing in for a list or
 * a set of table rows, wrapped in one region that names what's loading.
 *
 * The visually-hidden label is real text rather than an `aria-label` because
 * an empty `role="status"` announces nothing when it's already present at
 * first paint — which is when these almost always appear.
 */
const SkeletonList = ({
  rows = 5,
  label,
  className,
  itemClassName,
  ...props
}: React.ComponentProps<"div"> & {
  /** How many placeholder shapes to draw. */
  rows?: number;
  /** What's loading, e.g. "Loading boards". Read by screen readers. */
  label: string;
  /** Applied to each shape — this is where you set the row height. */
  itemClassName?: string;
}) => (
  <div role="status" aria-busy="true" className={cn("space-y-2", className)} {...props}>
    <span className="sr-only">{label}</span>
    {Array.from({ length: rows }, (_, i) => (
      <Skeleton key={i} className={cn("h-12", itemClassName)} />
    ))}
  </div>
);

export { Skeleton, SkeletonList };
