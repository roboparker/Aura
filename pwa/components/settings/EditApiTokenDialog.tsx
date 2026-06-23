import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import {
  updateApiToken,
  type ApiTokenRow,
  type TokenAccessPolicy,
} from "@/lib/apiTokens";
import {
  TOKEN_PERMISSION_LEVELS,
  TOKEN_PERMISSION_NODES,
  defaultTokenAccess,
} from "@/lib/tokenPermissions";
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
import { Switch } from "@/components/ui/switch";
import PermissionTree from "@/components/common/PermissionTree";

interface EditApiTokenDialogProps {
  /** The token to edit, or null when the dialog is closed. */
  token: ApiTokenRow | null;
  onOpenChange: (open: boolean) => void;
  /** Called with the updated token so the table can refresh in place. */
  onSaved: (token: ApiTokenRow) => void;
}

/**
 * Edits an existing token's permissions. The secret is immutable — this only
 * touches the access policy (the same none/view/edit category tree as the
 * create flow). Per-item overrides that may already exist on the policy are
 * preserved untouched; only the category grants are editable here.
 */
const EditApiTokenDialog = ({
  token,
  onOpenChange,
  onSaved,
}: EditApiTokenDialogProps) => {
  const [restricted, setRestricted] = useState(false);
  const [access, setAccess] = useState<Record<string, string>>(defaultTokenAccess);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Seed the form from the token each time the dialog opens for a new row.
  useEffect(() => {
    if (!token) return;
    const policy = token.accessPolicy;
    setRestricted(policy !== null);
    setAccess(
      policy
        ? { ...defaultTokenAccess(), ...policy.categories }
        : defaultTokenAccess(),
    );
    setError(null);
  }, [token]);

  const save = async () => {
    if (!token) return;
    setSubmitting(true);
    setError(null);
    try {
      // Keep any per-item overrides the policy already carried; only the
      // category grants are editable from this dialog.
      const items = token.accessPolicy?.items ?? {};
      const nextPolicy: TokenAccessPolicy | null = restricted
        ? { categories: access, items }
        : null;
      const updated = await updateApiToken(token["@id"], {
        accessPolicy: nextPolicy,
      });
      onSaved(updated);
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update token.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={token !== null} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit token permissions</DialogTitle>
          <DialogDescription>
            Adjust what{" "}
            <span className="font-medium text-foreground">{token?.name}</span>{" "}
            can do. The secret itself can&apos;t be changed — revoke and
            re-create to rotate it.
          </DialogDescription>
        </DialogHeader>

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <div className="space-y-2">
          <div className="flex items-center justify-between gap-4">
            <div>
              <p className="text-sm font-medium">Restrict permissions</p>
              <p className="text-xs text-muted-foreground">
                Off = the token can do everything you can. On = limit it per
                category.
              </p>
            </div>
            <Switch
              checked={restricted}
              onCheckedChange={setRestricted}
              aria-label="Restrict token permissions"
              data-testid="edit-api-token-restrict"
            />
          </div>
          {restricted ? (
            <div className="rounded-md border p-3">
              <PermissionTree
                levels={TOKEN_PERMISSION_LEVELS}
                nodes={TOKEN_PERMISSION_NODES}
                values={access}
                masterLabel="Token can access"
                onChange={setAccess}
              />
            </div>
          ) : null}
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
          >
            Cancel
          </Button>
          <Button
            type="button"
            onClick={() => void save()}
            disabled={submitting}
            data-testid="edit-api-token-save"
          >
            {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : "Save changes"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};

export default EditApiTokenDialog;
