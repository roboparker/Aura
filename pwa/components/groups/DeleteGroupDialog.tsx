import { useEffect, useState } from "react";
import { Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import type { Group } from "@/lib/groupTypes";
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
  group: Group;
  /** Called after a successful delete (e.g. to navigate away). */
  onDeleted: () => void;
}

/**
 * Type-to-confirm deletion dialog for a group. Group delete is open to any
 * member of the group's space (#groups-space) and is not step-up protected
 * (like tags / custom fields), so the only barrier is typing the group name
 * exactly. Deleting revokes the access the group grants its members to its
 * space (members who belong to the space directly keep their access).
 */
const DeleteGroupDialog = ({ open, onOpenChange, group, onDeleted }: Props) => {
  const [nameInput, setNameInput] = useState("");
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setNameInput("");
    setError(null);
    setIsDeleting(false);
  }, [open]);

  const memberCount = group.memberships.length;
  const spaceName = group.spaceSummary?.name;
  const nameMatches = nameInput === group.title;
  const canDelete = nameMatches && !isDeleting;

  const handleDelete = async () => {
    if (!canDelete) return;
    setIsDeleting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${group["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok && res.status !== 204) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.error ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to delete group.",
        );
      }
      onDeleted();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete group.");
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
              <DialogTitle>Delete this group?</DialogTitle>
              <DialogDescription>This cannot be undone.</DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <Alert variant="destructive">
          <AlertDescription>
            Removing <span className="font-semibold">{group.title}</span> takes
            its <span className="font-semibold">{memberCount}</span>{" "}
            {memberCount === 1 ? "member" : "members"} with it
            {spaceName ? (
              <>
                {" "}
                — they lose the access this group grants to{" "}
                <span className="font-semibold">{spaceName}</span> (members who
                belong to the space directly keep their access).
              </>
            ) : (
              "."
            )}
          </AlertDescription>
        </Alert>

        <div className="space-y-1.5">
          <Label htmlFor="delete-group-name">
            To confirm, type{" "}
            <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-foreground">
              {group.title}
            </code>{" "}
            <span className="text-muted-foreground font-normal">
              Type the name exactly to confirm
            </span>
          </Label>
          <Input
            id="delete-group-name"
            value={nameInput}
            onChange={(e) => setNameInput(e.target.value)}
            autoComplete="off"
            aria-label="Confirm group name"
          />
          {!nameMatches && nameInput !== "" && (
            <p className="text-xs text-muted-foreground font-mono">
              waiting for exact match…
            </p>
          )}
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
            className="gap-1.5"
            disabled={!canDelete}
            onClick={handleDelete}
          >
            <Trash2 className="h-4 w-4" aria-hidden />
            {isDeleting ? "Deleting…" : "Delete group"}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
};

export default DeleteGroupDialog;
