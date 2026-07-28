import * as React from "react";

import { cn } from "@/lib/utils";

const Card = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      className={cn(
        "rounded-xl border border-border/60 bg-card text-card-foreground lift",
        className,
      )}
      {...props}
    />
  ),
);
Card.displayName = "Card";

const CardHeader = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn("flex flex-col space-y-1.5 p-6", className)} {...props} />
  ),
);
CardHeader.displayName = "CardHeader";

/** Heading levels a CardTitle may render as. */
export type CardTitleAs = "h1" | "h2" | "h3" | "h4" | "h5" | "h6" | "div";

interface CardTitleProps extends React.HTMLAttributes<HTMLHeadingElement> {
  /**
   * Heading level to render. Defaults to `h2`, which is the right level on
   * these pages: the page title is the `h1` and a Card is a top-level section
   * beneath it, so `h2` keeps the outline contiguous (an `h3` default would
   * leave an h1 -> h3 jump on every card page). Pass a deeper level when a
   * card genuinely nests under another heading, or `div` to opt a purely
   * decorative title out of the outline entirely.
   */
  as?: CardTitleAs;
}

/**
 * Card titles are headings, not styled text. Rendering them as `div`s meant
 * heading navigation — the primary way screen-reader users skim a page —
 * returned nothing but the footer's column labels, and the auth screens had
 * no heading of any level at all. The styling is class-driven, so changing
 * the element is visually inert.
 */
const CardTitle = React.forwardRef<HTMLHeadingElement, CardTitleProps>(
  ({ className, as: Component = "h2", ...props }, ref) => (
    <Component
      ref={ref}
      className={cn("font-semibold leading-none tracking-tight", className)}
      {...props}
    />
  ),
);
CardTitle.displayName = "CardTitle";

const CardDescription = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn("text-sm text-muted-foreground", className)} {...props} />
  ),
);
CardDescription.displayName = "CardDescription";

const CardContent = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn("p-6 pt-0", className)} {...props} />
  ),
);
CardContent.displayName = "CardContent";

const CardFooter = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn("flex items-center p-6 pt-0", className)} {...props} />
  ),
);
CardFooter.displayName = "CardFooter";

export { Card, CardHeader, CardFooter, CardTitle, CardDescription, CardContent };
