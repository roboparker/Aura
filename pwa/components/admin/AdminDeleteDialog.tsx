import { useEffect, useState } from "react";
import { ShieldAlert } from "lucide-react";
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
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import type { DeletionTargetType } from "@/lib/deletionTypes";

interface UserAsset {
  id: string;
  name: string;
  memberCount: number;
}

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  targetType: DeletionTargetType;
  targetId: string;
  /** The exact string the admin must type to confirm. */
  targetLabel: string;
  twoFactorEnabled: boolean;
  onDone: () => void;
}

/**
 * Site-admin deletion of somebody else's data.
 *
 * The bar is deliberately no lower than a user deleting their own: a typed
 * reason (it goes into the audit log a human reads months later), the exact
 * name typed back, and step-up. "Delete immediately" is off by default — the
 * reversible path should be the one you fall into.
 */
const AdminDeleteDialog = ({
  open,
  onOpenChange,
  targetType,
  targetId,
  targetLabel,
  twoFactorEnabled,
  onDone,
}: Props) => {
  const [reason, setReason] = useState("");
  const [confirm, setConfirm] = useState("");
  const [credential, setCredential] = useState("");
  const [immediate, setImmediate] = useState(false);
  const [notifyOwner, setNotifyOwner] = useState(true);
  const [assets, setAssets] = useState<{
    organizations: UserAsset[];
    spaces: UserAsset[];
  } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setReason("");
    setConfirm("");
    setCredential("");
    setImmediate(false);
    setNotifyOwner(true);
    setAssets(null);
    setError(null);

    if (targetType !== "account") return;
    let cancelled = false;
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/admin/deletions/user-assets/${targetId}`,
          { credentials: "include", headers: { Accept: "application/json" } },
        );
        if (!res.ok) return;
        const data = await res.json();
        if (!cancelled) {
          setAssets({
            organizations: data.organizations ?? [],
            spaces: data.spaces ?? [],
          });
        }
      } catch {
        /* the blast-radius list is advisory */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [open, targetType, targetId]);

  const canSubmit =
    reason.trim() !== "" && confirm === targetLabel && credential.trim() !== "" && !busy;

  const submit = async () => {
    if (!canSubmit) return;
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/admin/deletions`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          targetType,
          targetId,
          reason: reason.trim(),
          confirm,
          immediate,
          notifyOwner,
          ...(twoFactorEnabled
            ? { totpCode: credential.trim() }
            : { currentPassword: credential }),
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || "Failed to delete.");
      onDone();
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete.");
    } finally {
      setBusy(false);
    }
  };

  const strandedCount =
    (assets?.organizations.length ?? 0) + (assets?.spaces.length ?? 0);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <div className="flex items-center gap-3">
            <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-destructive/15 text-destructive">
              <ShieldAlert className="h-5 w-5" aria-hidden />
            </span>
            <div>
              <DialogTitle>Delete {targetLabel}</DialogTitle>
              <DialogDescription>
                This is someone else&apos;s data. The action is audited.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        {assets && strandedCount > 0 && (
          <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
            <p className="font-medium">
              This user solely owns {strandedCount} other thing
              {strandedCount === 1 ? "" : "s"}
            </p>
            <ul className="mt-1.5 list-disc space-y-1 pl-4 text-muted-foreground">
              {assets.organizations.map((o) => (
                <li key={o.id}>
                  Organization <strong>{o.name}</strong> ({o.memberCount} members)
                </li>
              ))}
              {assets.spaces.map((s) => (
                <li key={s.id}>
                  Space <strong>{s.name}</strong> ({s.memberCount} members)
                </li>
              ))}
            </ul>
            <p className="mt-2 text-muted-foreground">
              These are <em>not</em> deleted with the account — the next member
              is promoted instead. Delete them separately if that&apos;s what you
              intend.
            </p>
          </div>
        )}

        <div className="space-y-1.5">
          <Label htmlFor="admin-delete-reason">
            Reason{" "}
            <span className="font-normal text-muted-foreground">
              Recorded in the audit log
            </span>
          </Label>
          <Textarea
            id="admin-delete-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="GDPR erasure request #1234 / spam account"
            rows={2}
          />
        </div>

        <div className="flex items-start justify-between gap-4 rounded-md border p-3">
          <div className="min-w-0">
            <p className="text-sm font-medium">Delete immediately</p>
            <p className="text-sm text-muted-foreground">
              {immediate
                ? "Skips the 30-day window. Permanent, with no restore link. Use for erasure requests and abuse takedowns."
                : "Off: schedules the normal 30-day deletion, which the owner can undo."}
            </p>
          </div>
          <Switch
            checked={immediate}
            onCheckedChange={setImmediate}
            aria-label="Delete immediately"
          />
        </div>

        <div className="flex items-start justify-between gap-4 rounded-md border p-3">
          <div className="min-w-0">
            <p className="text-sm font-medium">Email the owner</p>
            <p className="text-sm text-muted-foreground">
              Turn off for abuse takedowns where a warning would be unhelpful.
            </p>
          </div>
          <Switch
            checked={notifyOwner}
            onCheckedChange={setNotifyOwner}
            aria-label="Email the owner"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="admin-delete-confirm">
            Type{" "}
            <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-foreground">
              {targetLabel}
            </code>{" "}
            to confirm
          </Label>
          <Input
            id="admin-delete-confirm"
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            autoComplete="off"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="admin-delete-credential">
            {twoFactorEnabled ? "Your authentication code" : "Your password"}
          </Label>
          <Input
            id="admin-delete-credential"
            value={credential}
            onChange={(e) => setCredential(e.target.value)}
            type={twoFactorEnabled ? "text" : "password"}
            inputMode={twoFactorEnabled ? "numeric" : undefined}
            autoComplete={twoFactorEnabled ? "one-time-code" : "current-password"}
          />
        </div>

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={busy}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={submit} disabled={!canSubmit}>
            {busy
              ? "Working…"
              : immediate
                ? "Delete permanently"
                : "Schedule deletion"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};

export default AdminDeleteDialog;
