import { useEffect, useState } from "react";
import { ArrowRight, Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import type { Group } from "@/lib/groupTypes";
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
import SpaceTile from "@/components/spaces/SpaceTile";

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  group: Group;
  /** Called after a successful delete (e.g. to navigate away). */
  onDeleted: () => void;
}

const formatDate = (iso: string): string => {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleDateString(undefined, {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
};

/**
 * Type-to-confirm deletion dialog for a group. Group delete is owner-only
 * but not step-up protected (unlike space delete), so the only barrier is
 * typing the group name exactly — matching the server's security rule.
 *
 * The impact summary spells out that deleting the group revokes its
 * members from every attached space, with the caveat that members who
 * also belong to a space directly keep their access there.
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
  const spaces = group.attachedSpaces;
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
            Removing{" "}
            <span className="font-semibold">{group.title}</span> will revoke{" "}
            <span className="font-semibold">{memberCount}</span>{" "}
            {memberCount === 1 ? "member" : "members"} from{" "}
            <span className="font-semibold">{spaces.length}</span>{" "}
            {spaces.length === 1 ? "space" : "spaces"}.
          </AlertDescription>
        </Alert>

        {spaces.length > 0 && (
          <div className="space-y-2">
            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Spaces this group is attached to{" "}
              <span className="text-muted-foreground/70">{spaces.length}</span>
            </p>
            <ul className="space-y-2">
              {spaces.map((s) => (
                <li
                  key={s.id}
                  className="flex items-center gap-3 rounded-md border px-3 py-2"
                >
                  <SpaceTile
                    name={s.name}
                    color={s.color ?? s.ownerColor ?? "#334155"}
                    isPersonal={s.isPersonal}
                    size="sm"
                  />
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium truncate">{s.name}</p>
                    <p className="text-xs text-muted-foreground">
                      attached as {s.role} · since {formatDate(s.since)}
                    </p>
                  </div>
                  <span className="inline-flex items-center gap-1 whitespace-nowrap text-xs text-destructive">
                    <ArrowRight className="h-3 w-3" aria-hidden />
                    {memberCount} {memberCount === 1 ? "member" : "members"} lose
                    access
                  </span>
                </li>
              ))}
            </ul>
            <p className="text-xs text-muted-foreground">
              Members who also belong to those spaces directly keep their access
              — only access granted through this group is removed.
            </p>
          </div>
        )}

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
            className={cn("gap-1.5")}
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
