import * as React from "react";

import { cn } from "@/lib/utils";
import { inputElevationClass, type Elevation } from "@/lib/elevation";

export interface TextareaProps
  extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  /** Surface elevation; 0 = inline (flush, no border/bg until hover/focus). */
  elevation?: Elevation;
}

const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, elevation = 1, ...props }, ref) => {
    return (
      <textarea
        className={cn(
          "flex min-h-[60px] w-full rounded-md px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none aria-invalid:border-destructive aria-invalid:focus-visible:border-destructive disabled:cursor-not-allowed disabled:opacity-50",
          inputElevationClass(elevation),
          className,
        )}
        ref={ref}
        {...props}
      />
    );
  },
);
Textarea.displayName = "Textarea";

export { Textarea };
