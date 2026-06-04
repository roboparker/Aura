import { useEffect, useState } from "react";
import { ArrowRightLeft } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { type Group, toGroupAvatarUser } from "@/lib/groupTypes";
import { formatRelative } from "@/lib/relativeTime";
import { displayName } from "@/lib/userDisplay";
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
import UserAvatar from "@/components/user/UserAvatar";

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  group: Group;
  /** When true the step-up requires a TOTP code; otherwise a password. */
  twoFactorEnabled: boolean;
  /** Called after a successful transfer (e.g. to reload the group). */
  onTransferred: () => void;
}

/**
 * Pick-a-new-owner + step-up confirmation dialog. The caller proves
 * identity (TOTP when 2FA is on, otherwise the current password), which
 * the server re-checks through SensitiveActionVerifier before reassigning
 * the group. Mirrors {@link DeleteSpaceDialog}'s step-up shape.
 */
const TransferOwnershipDialog = ({
  open,
  onOpenChange,
  group,
  twoFactorEnabled,
  onTransferred,
}: Props) => {
  const candidates = group.memberships.filter(
    (m) => m.user.id !== group.owner.id,
  );

  const [selectedId, setSelectedId] = useState<string>("");
  const [credential, setCredential] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setSelectedId(candidates[0]?.user.id ?? "");
    setCredential("");
    setError(null);
    // candidates is derived from group; only reset when the dialog opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const selected = candidates.find((m) => m.user.id === selectedId);
  const canTransfer =
    selectedId !== "" && credential.trim() !== "" && !isSubmitting;

  const handleTransfer = async () => {
    if (!canTransfer || !selected) return;
    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/groups/${group.id}/transfer`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          newOwner: `/users/${selected.user.id}`,
          ...(twoFactorEnabled
            ? { totpCode: credential.trim() }
            : { currentPassword: credential }),
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to transfer ownership.");
      }
      onTransferred();
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Failed to transfer ownership.",
      );
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <div className="flex items-center gap-3">
            <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-amber-500/15 text-amber-600">
              <ArrowRightLeft className="h-5 w-5" aria-hidden />
            </span>
            <div>
              <DialogTitle>Transfer ownership</DialogTitle>
              <DialogDescription>
                Pass {group.title} to another member.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        {candidates.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            Add another member first — you can only transfer ownership to
            someone already in the group.
          </p>
        ) : (
          <>
            <div className="space-y-1.5">
              <Label>Pick a new owner</Label>
              <div
                role="radiogroup"
                aria-label="New owner"
                className="max-h-56 overflow-y-auto rounded-md border divide-y"
              >
                {candidates.map((m) => {
                  const isSel = m.user.id === selectedId;
                  return (
                    <button
                      key={m["@id"]}
                      type="button"
                      role="radio"
                      aria-checked={isSel}
                      onClick={() => setSelectedId(m.user.id)}
                      className={cn(
                        "flex w-full items-center gap-3 px-3 py-2 text-left transition-colors",
                        isSel ? "bg-accent" : "hover:bg-muted/50",
                      )}
                    >
                      <span
                        className={cn(
                          "h-3.5 w-3.5 shrink-0 rounded-full border",
                          isSel
                            ? "border-[5px] border-emerald-500"
                            : "border-input",
                        )}
                        aria-hidden
                      />
                      <UserAvatar user={toGroupAvatarUser(m.user)} size="sm" />
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm font-medium truncate">
                          {displayName(m.user)}
                        </span>
                        <span className="block text-xs text-muted-foreground truncate">
                          since {formatRelative(m.joinedAt)}
                        </span>
                      </span>
                    </button>
                  );
                })}
              </div>
            </div>

            {selected && (
              <div className="rounded-md border bg-muted/30 px-3 py-2.5 text-xs text-muted-foreground space-y-1">
                <p className="font-medium text-foreground">What changes</p>
                <p>
                  <span className="font-medium text-foreground">
                    {displayName(selected.user)}
                  </span>{" "}
                  becomes owner of {group.title}.
                </p>
                <p>
                  <span className="font-medium text-foreground">
                    {displayName(group.owner)}
                  </span>{" "}
                  stays in the group as a regular member.
                </p>
                <p>Attached spaces and their access are unchanged.</p>
              </div>
            )}

            <div className="space-y-1.5">
              <Label htmlFor="transfer-credential">
                {twoFactorEnabled ? "Authentication code" : "Your password"}{" "}
                <span className="text-muted-foreground font-normal">
                  {twoFactorEnabled
                    ? "From your authenticator app"
                    : "To confirm it's really you"}
                </span>
              </Label>
              <Input
                id="transfer-credential"
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
          </>
        )}

        <div className="flex items-center justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isSubmitting}
          >
            Cancel
          </Button>
          {candidates.length > 0 && (
            <Button
              type="button"
              className="gap-1.5 bg-amber-600 hover:bg-amber-500 text-white"
              disabled={!canTransfer}
              onClick={handleTransfer}
            >
              <ArrowRightLeft className="h-4 w-4" aria-hidden />
              {isSubmitting ? "Transferring…" : "Transfer"}
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
};

export default TransferOwnershipDialog;
