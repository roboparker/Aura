import { useState } from "react";
import { useRouter } from "next/router";
import { Download, Loader2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { useAuth } from "@/contexts/AuthContext";
import SettingsShell from "@/components/settings/SettingsShell";
import {
  DeleteAccountDialog,
  ExportDataDialog,
  StepUpField,
  stepUpBody,
} from "@/components/settings/AccountLifecycleDialogs";
import CancellationSurvey from "@/components/feedback/CancellationSurvey";
import { cancellationFeedbackBody } from "@/lib/cancellationReasons";
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

const DeactivateCard = ({
  twoFactorEnabled,
}: {
  twoFactorEnabled: boolean;
}) => {
  const { logout } = useAuth();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [value, setValue] = useState("");
  const [reason, setReason] = useState("");
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const feedback = cancellationFeedbackBody(reason, comment);

  const submit = async () => {
    if (!feedback) return;
    setSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/me/deactivate`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          ...stepUpBody(twoFactorEnabled, value),
          ...feedback,
        }),
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
          <CancellationSurvey
            idPrefix="deactivate"
            reason={reason}
            comment={comment}
            onReasonChange={setReason}
            onCommentChange={setComment}
            disabled={submitting}
          />
          <StepUpField twoFactorEnabled={twoFactorEnabled} value={value} onChange={setValue} />
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={() => void submit()}
              disabled={submitting || value.trim() === "" || !feedback}
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

  return (
    <Card>
      <CardHeader>
        <CardTitle>Export my data</CardTitle>
      </CardHeader>
      <CardContent className="flex items-center justify-between gap-4">
        <p className="text-sm text-muted-foreground">
          Build a zip of your profile, preferences, the content you authored,
          and the files you uploaded. We&apos;ll email you a download link when
          it&apos;s ready — links expire after 7 days.
        </p>
        <Button type="button" variant="outline" onClick={() => setOpen(true)} data-testid="export-open">
          <Download className="mr-1 h-4 w-4" /> Request export
        </Button>
      </CardContent>

      <ExportDataDialog open={open} onOpenChange={setOpen} twoFactorEnabled={twoFactorEnabled} />
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

  const handleDeleted = () => {
    logout();
    void router.replace("/signin");
  };

  return (
    <Card className="border-destructive/40">
      <CardHeader>
        <CardTitle className="text-destructive">Delete account</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <p className="text-sm text-muted-foreground">
          Permanently removes your Madori account. This cannot be undone.
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

      <DeleteAccountDialog
        open={open}
        onOpenChange={setOpen}
        twoFactorEnabled={twoFactorEnabled}
        email={email}
        onDeleted={handleDeleted}
      />
    </Card>
  );
};

export default DangerPage;
