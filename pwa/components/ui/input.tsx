import * as React from "react";

import { cn } from "@/lib/utils";
import { inputElevationClass, type Elevation } from "@/lib/elevation";

export interface InputProps
  extends React.InputHTMLAttributes<HTMLInputElement> {
  /** Surface elevation; 0 = inline (flush, no border/bg until hover/focus). */
  elevation?: Elevation;
}

const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ className, type, elevation = 1, ...props }, ref) => {
    return (
      <input
        type={type}
        className={cn(
          "flex h-9 w-full rounded-md px-3 py-1 text-sm file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none aria-invalid:border-destructive aria-invalid:focus-visible:border-destructive disabled:cursor-not-allowed disabled:opacity-50",
          inputElevationClass(elevation),
          className,
        )}
        ref={ref}
        {...props}
      />
    );
  },
);
Input.displayName = "Input";

export { Input };
