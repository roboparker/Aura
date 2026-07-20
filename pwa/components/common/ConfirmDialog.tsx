import { useState } from "react";
import { Loader2 } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

export interface ConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: React.ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Style the confirm button as destructive (red). */
  destructive?: boolean;
  /**
   * Run on confirm. While it's pending the buttons disable and the confirm
   * button shows a spinner; if it throws, the dialog stays open and surfaces
   * the error inline. On success the dialog closes.
   */
  onConfirm: () => void | Promise<void>;
}

/**
 * Shared confirmation modal — the replacement for scattered `window.confirm`
 * calls. Controlled via `open` / `onOpenChange`, it owns the async confirm
 * lifecycle (pending spinner, inline error, close-on-success), so callers just
 * pass an `onConfirm` that throws on failure. While the action is in flight the
 * dialog can't be dismissed (Escape / outside-click / X are ignored).
 */
const ConfirmDialog = ({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel = "Confirm",
  cancelLabel = "Cancel",
  destructive,
  onConfirm,
}: ConfirmDialogProps) => {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const close = () => {
    if (busy) return;
    setError(null);
    onOpenChange(false);
  };

  const handleConfirm = async () => {
    setBusy(true);
    setError(null);
    try {
      await onConfirm();
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next: boolean) => (next ? onOpenChange(true) : close())}
    >
      <DialogContent className="sm:max-w-md" data-testid="confirm-dialog">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description && <DialogDescription>{description}</DialogDescription>}
        </DialogHeader>
        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={close}
            disabled={busy}
            data-testid="confirm-dialog-cancel"
          >
            {cancelLabel}
          </Button>
          <Button
            type="button"
            variant={destructive ? "destructive" : "default"}
            onClick={() => void handleConfirm()}
            disabled={busy}
            data-testid="confirm-dialog-confirm"
          >
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};

export default ConfirmDialog;
