import { Check, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import type { SaveStatus } from "@/lib/usePreferencePersist";

/**
 * Passive status pill for auto-saved Settings panels — appears only during
 * the saving/saved transitions so the page doesn't grow a permanent sticker.
 */
const SaveIndicator = ({ status }: { status: SaveStatus }) => {
  if (status === "idle" || status === "error") return null;
  return (
    <Button
      variant="outline"
      size="sm"
      type="button"
      disabled
      className="pointer-events-none gap-1.5"
      data-testid={`settings-save-${status}`}
    >
      {status === "saving" ? (
        <>
          <Loader2 className="h-3.5 w-3.5 animate-spin" /> Saving…
        </>
      ) : (
        <>
          <Check className="h-3.5 w-3.5" /> Saved
        </>
      )}
    </Button>
  );
};

export default SaveIndicator;
