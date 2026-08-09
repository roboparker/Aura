import { useState } from "react";
import { AlertTriangle, RotateCcw } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  formatPurgeDate,
  remainingLabel,
  type DeletionTargetType,
} from "@/lib/deletionTypes";

interface Props {
  targetType: Exclude<DeletionTargetType, "account">;
  name: string;
  purgeAfter: string | null | undefined;
  /** When true the step-up asks for a TOTP code rather than a password. */
  twoFactorEnabled: boolean;
  /** POSTs the restore; resolves on success, throws with a message otherwise. */
  onRestore: (credential: string) => Promise<void>;
  /** Set when something else has to be restored first (e.g. the parent org). */
  blockedReason?: string | null;
}

/**
 * The persistent "this is scheduled for deletion" bar shown on an organization
 * or space that's inside its grace period, with the in-app way back.
 *
 * It leads with the deadline rather than the fact of deletion: everyone who
 * lands here already knows it was deleted, and the only new information is how
 * long they have. The button is deliberately not destructive-styled — restoring
 * is the safe action here.
 */
const ScheduledDeletionBanner = ({
  targetType,
  name,
  purgeAfter,
  twoFactorEnabled,
  onRestore,
  blockedReason,
}: Props) => {
  const [open, setOpen] = useState(false);
  const [credential, setCredential] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const remaining = remainingLabel(purgeAfter);
  const deadline = formatPurgeDate(purgeAfter);

  const submit = async () => {
    if (credential.trim() === "") return;
    setBusy(true);
    setError(null);
    try {
      await onRestore(credential);
      setOpen(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not restore.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <div
        className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4"
        role="status"
      >
        <div className="flex flex-wrap items-start gap-3">
          <AlertTriangle
            className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-500"
            aria-hidden
          />
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold">
              This {targetType} is scheduled for deletion
              {remaining ? ` — ${remaining}` : ""}
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              <span className="font-medium text-foreground">{name}</span> and
              everything in it is hidden from everyone who had access. Nothing
              has been permanently deleted yet
              {deadline ? `, and nothing will be until ${deadline}` : ""}.
            </p>
            {blockedReason && (
              <p className="mt-2 text-sm text-muted-foreground">{blockedReason}</p>
            )}
          </div>
          {!blockedReason && (
            <Button
              variant="outline"
              onClick={() => {
                setCredential("");
                setError(null);
                setOpen(true);
              }}
            >
              <RotateCcw className="mr-2 h-4 w-4" aria-hidden />
              Restore
            </Button>
          )}
        </div>
      </div>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Restore {name}?</DialogTitle>
            <DialogDescription>
              This cancels the scheduled deletion and makes the {targetType}{" "}
              visible to its members again.
            </DialogDescription>
          </DialogHeader>

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="restore-credential">
              {twoFactorEnabled ? "Authentication code" : "Current password"}{" "}
              <span className="font-normal text-muted-foreground">
                {twoFactorEnabled
                  ? "From your authenticator app"
                  : "Confirm it's really you"}
              </span>
            </Label>
            <Input
              id="restore-credential"
              value={credential}
              onChange={(e) => setCredential(e.target.value)}
              type={twoFactorEnabled ? "text" : "password"}
              inputMode={twoFactorEnabled ? "numeric" : undefined}
              autoComplete={
                twoFactorEnabled ? "one-time-code" : "current-password"
              }
              placeholder={twoFactorEnabled ? "123 456" : undefined}
            />
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={busy}>
              Cancel
            </Button>
            <Button onClick={submit} disabled={busy || credential.trim() === ""}>
              {busy ? "Restoring…" : "Restore"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
};

export default ScheduledDeletionBanner;
