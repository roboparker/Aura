import { Toaster as SonnerToaster } from "sonner";

/**
 * App-wide toast host. Mounted once in `_app.tsx`.
 *
 * Madori ships a single dark theme (see `styles/globals.css`), so the theme is
 * pinned rather than read from a provider. Colours come from the design tokens
 * instead of Sonner's defaults so toasts match cards and dialogs.
 *
 * Toasts are for transient confirmation of an action the user just took —
 * especially ones offering an `action` such as Undo. They are not a substitute
 * for inline error state on a form.
 */
const Toaster = ({ ...props }: React.ComponentProps<typeof SonnerToaster>) => (
  <SonnerToaster
    theme="dark"
    position="bottom-right"
    // Sonner defaults to visible-on-hover only; keep the close button always
    // available so a toast offering Undo can be dismissed without waiting.
    closeButton
    toastOptions={{
      classNames: {
        toast:
          "group flex items-center gap-3 rounded-md border border-border bg-card p-4 text-sm text-card-foreground shadow-lg",
        description: "text-muted-foreground",
        actionButton:
          "rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90",
        cancelButton:
          "rounded-md bg-muted px-3 py-1.5 text-xs font-medium text-muted-foreground hover:bg-muted/80",
        closeButton: "border-border bg-card text-muted-foreground hover:text-foreground",
        error: "border-destructive/50",
      },
    }}
    {...props}
  />
);

export { Toaster };
