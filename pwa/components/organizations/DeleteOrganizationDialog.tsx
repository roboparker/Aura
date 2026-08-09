import { useEffect, useState } from "react";
import { Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import type { Organization } from "@/lib/organizationTypes";
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
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import CancellationSurvey from "@/components/feedback/CancellationSurvey";
import { cancellationFeedbackBody } from "@/lib/cancellationReasons";

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  organization: Organization;
  /** When true the step-up requires a TOTP code; otherwise a password. */
  twoFactorEnabled: boolean;
  /** Called after a successful schedule, with the purge date. */
  onScheduled: (purgeAfter: string) => void;
}

/**
 * Type-to-confirm + step-up dialog for deleting an organization — the most
 * far-reaching delete in the product, since an org owns spaces and those own
 * everything else.
 *
 * The copy leads with what *doesn't* happen: nothing is destroyed today, and
 * there's a 30-day window plus an emailed link to undo it. That's a deliberate
 * choice over the usual "this cannot be undone" scare copy, because here it
 * would be false — and the true version is genuinely more reassuring.
 */
const DeleteOrganizationDialog = ({
  open,
  onOpenChange,
  organization,
  twoFactorEnabled,
  onScheduled,
}: Props) => {
  const [nameInput, setNameInput] = useState("");
  const [credential, setCredential] = useState("");
  const [reason, setReason] = useState("");
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setNameInput("");
    setCredential("");
    setReason("");
    setComment("");
    setError(null);
  }, [open]);

  const feedback = cancellationFeedbackBody(reason, comment);
  const nameMatches = nameInput === organization.name;
  const canSubmit =
    nameMatches && credential.trim() !== "" && Boolean(feedback) && !submitting;

  const submit = async () => {
    if (!canSubmit || !feedback) return;
    setSubmitting(true);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/organizations/${organization.id}/delete`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            confirmName: nameInput,
            ...(twoFactorEnabled
              ? { totpCode: credential.trim() }
              : { currentPassword: credential }),
            ...feedback,
          }),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.error || "Failed to delete organization.");
      }
      onScheduled(typeof data.purgeAfter === "string" ? data.purgeAfter : "");
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Failed to delete organization.",
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <div className="flex items-center gap-3">
            <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-destructive/15 text-destructive">
              <Trash2 className="h-5 w-5" aria-hidden />
            </span>
            <div>
              <DialogTitle>Delete this organization?</DialogTitle>
              <DialogDescription>
                You&apos;ll have 30 days to change your mind.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
          <p className="font-medium">What happens now</p>
          <ul className="mt-1.5 list-disc space-y-1 pl-4 text-muted-foreground">
            <li>
              Every space in {organization.name} becomes inaccessible to its{" "}
              {organization.seatCount} member
              {organization.seatCount === 1 ? "" : "s"} immediately.
            </li>
            <li>Any active subscription is cancelled straight away.</li>
            <li>
              We&apos;ll email every owner a link that restores everything, valid
              for 30 days.
            </li>
            <li>
              After 30 days it&apos;s permanently deleted. <em>That</em>{" "}
              can&apos;t be undone.
            </li>
          </ul>
        </div>

        <CancellationSurvey
          reason={reason}
          comment={comment}
          onReasonChange={setReason}
          onCommentChange={setComment}
          idPrefix="delete-org"
        />

        <div className="space-y-1.5">
          <Label htmlFor="delete-org-name">
            To confirm, type{" "}
            <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-foreground">
              {organization.name}
            </code>
          </Label>
          <Input
            id="delete-org-name"
            value={nameInput}
            onChange={(e) => setNameInput(e.target.value)}
            autoComplete="off"
            aria-label="Confirm organization name"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="delete-org-credential">
            {twoFactorEnabled ? "Authentication code" : "Current password"}{" "}
            <span className="font-normal text-muted-foreground">
              {twoFactorEnabled
                ? "From your authenticator app"
                : "Confirm it's really you"}
            </span>
          </Label>
          <Input
            id="delete-org-credential"
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

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
          >
            Cancel
          </Button>
          <Button variant="destructive" onClick={submit} disabled={!canSubmit}>
            {submitting ? "Scheduling…" : "Schedule deletion"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};

export default DeleteOrganizationDialog;
