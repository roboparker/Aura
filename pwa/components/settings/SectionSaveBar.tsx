import { Button } from "@/components/ui/button";
import SaveIndicator from "@/components/settings/SaveIndicator";
import type { SaveStatus } from "@/lib/usePreferencePersist";

interface SectionSaveBarProps {
  dirty: boolean;
  status: SaveStatus;
  error?: string | null;
  onSave: () => void;
  onDiscard: () => void;
  /** Test hook applied to the Save button (`{testId}`) + Discard (`{testId}-discard`). */
  testId?: string;
}

/**
 * Save / Discard footer for an explicit-save Settings card. Buttons are
 * disabled while clean or mid-save; the transient SaveIndicator pill shows the
 * saving/saved transition, and an inline message surfaces save errors.
 */
const SectionSaveBar = ({
  dirty,
  status,
  error,
  onSave,
  onDiscard,
  testId,
}: SectionSaveBarProps) => {
  const busy = status === "saving";

  return (
    <div className="mt-4 flex items-center justify-end gap-3 border-t pt-3">
      {status === "error" && error ? (
        <span className="mr-auto text-xs text-destructive">{error}</span>
      ) : null}
      <SaveIndicator status={status} />
      <Button
        type="button"
        variant="ghost"
        size="sm"
        onClick={onDiscard}
        disabled={!dirty || busy}
        data-testid={testId ? `${testId}-discard` : undefined}
      >
        Discard
      </Button>
      <Button
        type="button"
        size="sm"
        onClick={onSave}
        disabled={!dirty || busy}
        data-testid={testId}
      >
        Save changes
      </Button>
    </div>
  );
};

export default SectionSaveBar;
