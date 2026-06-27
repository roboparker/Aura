import { useState } from "react";
import { Lock, LockOpen } from "lucide-react";
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

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  space: Space;
  /** The visibility we're switching TO. */
  target: "private" | "shared";
  pendingInviteCount: number;
  /** Called after the change is applied (e.g. to reload + close). */
  onChanged: () => void;
}

/**
 * Confirmation step for flipping a space's visibility. Going private is
 * destructive — the server removes every non-creator membership and
 * revokes pending invites — so the dialog spells out exactly who loses
 * access before applying. Going shared is reversible-friendly and just
 * re-opens invites. Creator-only is enforced server-side.
 */
const ChangeVisibilityDialog = ({
  open,
  onOpenChange,
  space,
  target,
  pendingInviteCount,
  onChanged,
}: Props) => {
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const toPrivate = target === "private";
  const memberCount = space.userMemberships.filter(
    (m) => m.user.id !== space.createdBy?.id,
  ).length;
  const groupCount = space.groupsCount;

  const impact: { count: number; label: string }[] = [
    { count: memberCount, label: "members removed" },
    ...(groupCount > 0 ? [{ count: groupCount, label: "groups removed" }] : []),
    { count: pendingInviteCount, label: "pending invites revoked" },
  ];

  const handleConfirm = async () => {
    setIsSaving(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ visibility: target }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.detail ||
            data["hydra:description"] ||
            data.error ||
            "Failed to change visibility.",
        );
      }
      onChanged();
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Failed to change visibility.",
      );
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <div className="flex items-center gap-3">
            <span
              className={cn(
                "inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md",
                toPrivate
                  ? "bg-destructive/15 text-destructive"
                  : "bg-emerald-500/15 text-emerald-600",
              )}
            >
              {toPrivate ? (
                <Lock className="h-5 w-5" aria-hidden />
              ) : (
                <LockOpen className="h-5 w-5" aria-hidden />
              )}
            </span>
            <div>
              <DialogTitle>
                {toPrivate
                  ? "Make this space private?"
                  : "Make this space shared?"}
              </DialogTitle>
              <DialogDescription>
                {toPrivate
                  ? "Only you will keep access."
                  : "You'll be able to invite members again."}
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        {toPrivate ? (
          <>
            <p className="text-sm text-muted-foreground">
              Switching{" "}
              <span className="font-semibold text-foreground">{space.name}</span>{" "}
              to private removes everyone but you. The content stays — projects,
              pages, and tasks aren&apos;t deleted or reassigned — but only you
              will be able to see it.
            </p>
            <ul className="space-y-2">
              {impact.map((row) => (
                <li
                  key={row.label}
                  className="flex items-center gap-3 rounded-md border border-destructive/30 px-3 py-2 text-sm"
                >
                  <span className="font-mono font-semibold text-destructive w-8 text-right">
                    {row.count}
                  </span>
                  <span className="text-muted-foreground">{row.label}</span>
                </li>
              ))}
            </ul>
          </>
        ) : (
          <p className="text-sm text-muted-foreground">
            Switching{" "}
            <span className="font-semibold text-foreground">{space.name}</span>{" "}
            back to shared lets you invite members again. Anyone removed when it
            went private isn&apos;t restored automatically — you&apos;ll need to
            re-invite them.
          </p>
        )}

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
            disabled={isSaving}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant={toPrivate ? "destructive" : "default"}
            className={cn("gap-1.5", !toPrivate && "bg-emerald-600 hover:bg-emerald-500 text-white")}
            onClick={handleConfirm}
            disabled={isSaving}
          >
            {toPrivate ? (
              <Lock className="h-4 w-4" aria-hidden />
            ) : (
              <LockOpen className="h-4 w-4" aria-hidden />
            )}
            {isSaving ? "Working…" : toPrivate ? "Make private" : "Make shared"}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
};

export default ChangeVisibilityDialog;
