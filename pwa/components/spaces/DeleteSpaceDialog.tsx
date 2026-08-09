import { useEffect, useState } from "react";
import { Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import type { Space } from "@/contexts/ActiveSpaceContext";
import { cn } from "@/lib/utils";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  space: Space;
  /** When true the step-up requires a TOTP code; otherwise a password. */
  twoFactorEnabled: boolean;
  /** Called after a successful delete (e.g. to navigate away). */
  onDeleted: () => void;
}

/**
 * Type-to-confirm + step-up deletion dialog for a space. The user must
 * type the space name exactly AND prove identity — a TOTP code when 2FA
 * is enabled, otherwise their current password — which the server
 * re-checks through SensitiveActionVerifier before the row is removed.
 */
const DeleteSpaceDialog = ({
  open,
  onOpenChange,
  space,
  twoFactorEnabled,
  onDeleted,
}: Props) => {
  const [nameInput, setNameInput] = useState("");
  const [credential, setCredential] = useState("");
  const [taskCount, setTaskCount] = useState<number | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Reset on open and fetch the task count for the impact summary.
  useEffect(() => {
    if (!open) return;
    setNameInput("");
    setCredential("");
    setError(null);
    setTaskCount(null);
    let cancelled = false;
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/tasks?board.space=${encodeURIComponent(space["@id"])}&itemsPerPage=1`,
          { credentials: "include", headers: { Accept: "application/ld+json" } },
        );
        if (!res.ok) return;
        const data = await res.json();
        const total = data["hydra:totalItems"] ?? data.totalItems ?? 0;
        if (!cancelled) setTaskCount(typeof total === "number" ? total : 0);
      } catch {
        /* count is best-effort */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [open, space]);

  const nameMatches = nameInput === space.name;
  const memberCount = space.userMemberships.length;
  const canDelete = nameMatches && credential.trim() !== "" && !isDeleting;

  const rows: { count: number | null; label: string }[] = [
    { count: space.boardsCount, label: "boards" },
    { count: space.pagesCount, label: "pages" },
    { count: taskCount, label: "tasks" },
    { count: memberCount, label: "members will lose access" },
  ];

  const handleDelete = async () => {
    if (!canDelete) return;
    setIsDeleting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
        method: "DELETE",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(
          twoFactorEnabled
            ? { totpCode: credential.trim() }
            : { currentPassword: credential },
        ),
      });
      if (!res.ok && res.status !== 204) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.error ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to delete space.",
        );
      }
      onDeleted();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete space.");
    } finally {
      setIsDeleting(false);
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
              <DialogTitle>Delete this space?</DialogTitle>
              <DialogDescription>
                You&apos;ll have 30 days to change your mind.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <p className="text-sm text-muted-foreground">
          <span className="font-semibold text-foreground">{space.name}</span> and
          everything inside it becomes inaccessible right away:
        </p>

        <ul className="space-y-2">
          {rows.map((row) => (
            <li
              key={row.label}
              className="flex items-center gap-3 rounded-md border border-destructive/30 px-3 py-2 text-sm"
            >
              <span className="font-mono font-semibold text-destructive w-8 text-right">
                {row.count ?? "—"}
              </span>
              <span className="text-muted-foreground">{row.label}</span>
            </li>
          ))}
        </ul>

        <p className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-muted-foreground">
          Nothing is permanently deleted today. We&apos;ll email every space
          admin a link that restores all of this, valid for 30 days — after that
          it&apos;s gone for good.
        </p>

        <div className="space-y-1.5">
          <Label htmlFor="delete-confirm-name">
            To confirm, type{" "}
            <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-foreground">
              {space.name}
            </code>{" "}
            <span className="text-muted-foreground font-normal">
              Type the name exactly to confirm
            </span>
          </Label>
          <Input
            id="delete-confirm-name"
            value={nameInput}
            onChange={(e) => setNameInput(e.target.value)}
            autoComplete="off"
            aria-label="Confirm space name"
          />
          {!nameMatches && nameInput !== "" && (
            <p className="text-xs text-muted-foreground font-mono">
              waiting for exact match…
            </p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="delete-confirm-credential">
            {twoFactorEnabled
              ? "Authentication code"
              : "Current password"}{" "}
            <span className="text-muted-foreground font-normal">
              {twoFactorEnabled
                ? "From your authenticator app"
                : "Confirm it's really you"}
            </span>
          </Label>
          <Input
            id="delete-confirm-credential"
            value={credential}
            onChange={(e) => setCredential(e.target.value)}
            type={twoFactorEnabled ? "text" : "password"}
            inputMode={twoFactorEnabled ? "numeric" : undefined}
            autoComplete={twoFactorEnabled ? "one-time-code" : "current-password"}
            placeholder={twoFactorEnabled ? "123 456" : undefined}
          />
        </div>

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <div className="flex items-center justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isDeleting}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="destructive"
            className={cn("gap-1.5")}
            disabled={!canDelete}
            onClick={handleDelete}
          >
            <Trash2 className="h-4 w-4" aria-hidden />
            {isDeleting ? "Deleting…" : "Delete space"}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
};

export default DeleteSpaceDialog;
