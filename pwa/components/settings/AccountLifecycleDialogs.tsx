import { useState } from "react";
import { Loader2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
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

/**
 * Step-up confirmation dialogs for the account lifecycle endpoints
 * (`/me/export`, `/me/delete`). Shared between the Settings danger zone and
 * the waitlist gate page — waitlisted accounts must still be able to export
 * and delete their data (GDPR/CCPA) even though the rest of the app is
 * closed to them.
 */

/** Shared credential input — TOTP code when 2FA is on, else password. */
export const StepUpField = ({
  twoFactorEnabled,
  value,
  onChange,
}: {
  twoFactorEnabled: boolean;
  value: string;
  onChange: (v: string) => void;
}) => (
  <div className="space-y-1.5">
    <Label htmlFor="danger-stepup">
      {twoFactorEnabled ? "Authentication code" : "Current password"}
    </Label>
    <Input
      id="danger-stepup"
      type={twoFactorEnabled ? "text" : "password"}
      inputMode={twoFactorEnabled ? "numeric" : undefined}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      autoComplete={twoFactorEnabled ? "one-time-code" : "current-password"}
      data-testid="danger-stepup-input"
    />
  </div>
);

export const stepUpBody = (twoFactorEnabled: boolean, value: string) =>
  twoFactorEnabled ? { totpCode: value } : { currentPassword: value };

export const ExportDataDialog = ({
  open,
  onOpenChange,
  twoFactorEnabled,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  twoFactorEnabled: boolean;
}) => {
  const [value, setValue] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/me/export`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(stepUpBody(twoFactorEnabled, value)),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to export.");
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "aura-export.json";
      a.click();
      URL.revokeObjectURL(url);
      onOpenChange(false);
      setValue("");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Export your data</DialogTitle>
          <DialogDescription>Confirm to download your data.</DialogDescription>
        </DialogHeader>
        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        <StepUpField twoFactorEnabled={twoFactorEnabled} value={value} onChange={setValue} />
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            Cancel
          </Button>
          <Button
            type="button"
            onClick={() => void submit()}
            disabled={submitting || value.trim() === ""}
            data-testid="export-confirm"
          >
            {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : "Download"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};

export const DeleteAccountDialog = ({
  open,
  onOpenChange,
  twoFactorEnabled,
  email,
  onDeleted,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  twoFactorEnabled: boolean;
  email: string;
  onDeleted: () => void;
}) => {
  const [confirmEmail, setConfirmEmail] = useState("");
  const [value, setValue] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const emailMatches = confirmEmail.trim().toLowerCase() === email.toLowerCase();

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/me/delete`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ confirmEmail, ...stepUpBody(twoFactorEnabled, value) }),
      });
      if (!res.ok && res.status !== 204) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to delete account.");
      }
      onDeleted();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle className="text-destructive">Delete your account</DialogTitle>
          <DialogDescription>
            This permanently deletes {email}. Type your email and confirm.
          </DialogDescription>
        </DialogHeader>
        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        <div className="space-y-1.5">
          <Label htmlFor="delete-confirm-email">Type {email} to confirm</Label>
          <Input
            id="delete-confirm-email"
            type="email"
            value={confirmEmail}
            onChange={(e) => setConfirmEmail(e.target.value)}
            autoComplete="off"
            data-testid="delete-confirm-email"
          />
        </div>
        <StepUpField twoFactorEnabled={twoFactorEnabled} value={value} onChange={setValue} />
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            Cancel
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={() => void submit()}
            disabled={submitting || !emailMatches || value.trim() === ""}
            data-testid="delete-confirm"
          >
            {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : "Delete my account"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
