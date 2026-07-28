import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

const badgeVariants = cva(
  "inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-ring/60 focus:ring-offset-2 focus:ring-offset-background",
  {
    // Tinted-translucent rather than solid fills. In a data-dense table a row
    // of saturated chips flattens hierarchy — everything shouts, so nothing
    // reads as urgent. Solid fills stay available for states that genuinely
    // need to interrupt (see `solid` / `solidDestructive`).
    variants: {
      variant: {
        default:
          "border-primary/25 bg-primary/12 text-primary hover:bg-primary/18",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground hover:bg-secondary/80",
        destructive:
          "border-destructive/25 bg-destructive/12 text-destructive hover:bg-destructive/18",
        outline: "text-foreground",
        solid:
          "border-transparent bg-primary text-primary-foreground hover:bg-primary/90",
        solidDestructive:
          "border-transparent bg-destructive text-destructive-foreground hover:bg-destructive/90",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
);

export interface BadgeProps
  extends React.HTMLAttributes<HTMLSpanElement>,
    VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
