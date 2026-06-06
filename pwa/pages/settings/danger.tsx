import { useState } from "react";
import { useRouter } from "next/router";
import { Download, Loader2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { useAuth } from "@/contexts/AuthContext";
import SettingsShell from "@/components/settings/SettingsShell";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
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

const DELETE_CONSEQUENCES = [
  "Spaces you solely admin are transferred to another member, or archived if empty.",
  "Pages, discussions and comments you authored stay published, reattributed to “Former member”.",
  "Tasks & projects you own are kept under “Former member”; you're unassigned everywhere.",
  "API tokens and sessions are revoked immediately.",
  "You're signed out of all devices. This cannot be undone.",
];

const DangerPage = () => {
  const { user } = useAuth();
  const twoFactorEnabled = Boolean(user?.twoFactor.enabled);

  return (
    <SettingsShell
      active="danger"
      title="Danger zone"
      description="Destructive actions on your account. Most can't be undone."
    >
      <DeactivateCard twoFactorEnabled={twoFactorEnabled} />
      <ExportCard twoFactorEnabled={twoFactorEnabled} />
      <DeleteCard twoFactorEnabled={twoFactorEnabled} email={user?.email ?? ""} />
    </SettingsShell>
  );
};

/** Shared credential input — TOTP code when 2FA is on, else password. */
const StepUpField = ({
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

const stepUpBody = (twoFactorEnabled: boolean, value: string) =>
  twoFactorEnabled ? { totpCode: value } : { currentPassword: value };

const DeactivateCard = ({
  twoFactorEnabled,
}: {
  twoFactorEnabled: boolean;
}) => {
  const { logout } = useAuth();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [value, setValue] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/me/deactivate`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(stepUpBody(twoFactorEnabled, value)),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to deactivate.");
      }
      logout();
      void router.replace("/signin");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-destructive">Deactivate account</CardTitle>
      </CardHeader>
      <CardContent className="flex items-center justify-between gap-4">
        <p className="text-sm text-muted-foreground">
          Signs you out of every device and pauses your account. Sign back in any
          time to reactivate it.
        </p>
        <Button
          type="button"
          variant="outline"
          onClick={() => setOpen(true)}
          data-testid="deactivate-open"
        >
          Deactivate
        </Button>
      </CardContent>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Deactivate your account?</DialogTitle>
            <DialogDescription>
              You&apos;ll be signed out everywhere. Confirm to continue.
            </DialogDescription>
          </DialogHeader>
          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <StepUpField twoFactorEnabled={twoFactorEnabled} value={value} onChange={setValue} />
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={() => void submit()}
              disabled={submitting || value.trim() === ""}
              data-testid="deactivate-confirm"
            >
              {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : "Deactivate"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
};

const ExportCard = ({ twoFactorEnabled }: { twoFactorEnabled: boolean }) => {
  const [open, setOpen] = useState(false);
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
      setOpen(false);
      setValue("");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Export my data</CardTitle>
      </CardHeader>
      <CardContent className="flex items-center justify-between gap-4">
        <p className="text-sm text-muted-foreground">
          Download a JSON file of your profile, preferences, and the content you
          authored.
        </p>
        <Button type="button" variant="outline" onClick={() => setOpen(true)} data-testid="export-open">
          <Download className="mr-1 h-4 w-4" /> Request export
        </Button>
      </CardContent>

      <Dialog open={open} onOpenChange={setOpen}>
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
            <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={submitting}>
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
    </Card>
  );
};

const DeleteCard = ({
  twoFactorEnabled,
  email,
}: {
  twoFactorEnabled: boolean;
  email: string;
}) => {
  const { logout } = useAuth();
  const router = useRouter();
  const [open, setOpen] = useState(false);
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
      logout();
      void router.replace("/signin");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Card className="border-destructive/40">
      <CardHeader>
        <CardTitle className="text-destructive">Delete account</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <p className="text-sm text-muted-foreground">
          Permanently removes your Aura account. This cannot be undone.
        </p>
        <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
          {DELETE_CONSEQUENCES.map((c) => (
            <li key={c}>{c}</li>
          ))}
        </ul>
        <Button
          type="button"
          variant="destructive"
          onClick={() => setOpen(true)}
          data-testid="delete-open"
        >
          Delete account…
        </Button>
      </CardContent>

      <Dialog open={open} onOpenChange={setOpen}>
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
            <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={submitting}>
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
    </Card>
  );
};

export default DangerPage;
