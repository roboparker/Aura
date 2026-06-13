import { useState } from "react";
import { Check, Copy, Loader2 } from "lucide-react";
import {
  EXPIRY_OPTIONS,
  createApiToken,
  expiryIsoFromDays,
  type CreatedApiToken,
} from "@/lib/apiTokens";
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
import { cn } from "@/lib/utils";
import PermissionTree, {
  type PermissionLevel,
  type PermissionNode,
} from "@/components/common/PermissionTree";

const LEVELS: PermissionLevel[] = [
  { value: "none", label: "None" },
  { value: "view", label: "View" },
  { value: "edit", label: "Edit" },
];

const NODES: PermissionNode[] = [
  {
    key: "content",
    label: "Content",
    description: "tasks, projects, pages, discussions, comments",
    children: [
      { key: "tasks", label: "Tasks" },
      { key: "projects", label: "Projects" },
      { key: "pages", label: "Pages" },
      { key: "discussions", label: "Discussions" },
      { key: "comments", label: "Comments" },
    ],
  },
  { key: "notifications", label: "Notifications" },
  { key: "files", label: "Files" },
];

const ALL_CATEGORIES = [
  "tasks",
  "projects",
  "pages",
  "discussions",
  "comments",
  "notifications",
  "files",
];

// When a token is restricted, start everything at read-only — a useful,
// least-surprising baseline the user tightens or loosens.
const defaultAccess = (): Record<string, string> =>
  Object.fromEntries(ALL_CATEGORIES.map((c) => [c, "view"]));

interface CreateApiTokenDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Called after a token is created so the table can refetch. */
  onCreated: () => void;
}

const CreateApiTokenDialog = ({
  open,
  onOpenChange,
  onCreated,
}: CreateApiTokenDialogProps) => {
  const [name, setName] = useState("");
  const [expiry, setExpiry] = useState("90");
  const [restricted, setRestricted] = useState(false);
  const [access, setAccess] = useState<Record<string, string>>(defaultAccess);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [created, setCreated] = useState<CreatedApiToken | null>(null);
  const [copied, setCopied] = useState(false);

  const reset = () => {
    setName("");
    setExpiry("90");
    setRestricted(false);
    setAccess(defaultAccess());
    setError(null);
    setCreated(null);
    setCopied(false);
  };

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      const option = EXPIRY_OPTIONS.find((o) => o.value === expiry);
      const token = await createApiToken({
        name: name.trim(),
        accessPolicy: restricted ? { categories: access, items: {} } : null,
        expiresAt: expiryIsoFromDays(option?.days ?? null),
      });
      setCreated(token);
      onCreated();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create token.");
    } finally {
      setSubmitting(false);
    }
  };

  const copyToken = async () => {
    if (!created) return;
    try {
      await navigator.clipboard.writeText(created.plainToken);
      setCopied(true);
    } catch {
      /* clipboard blocked — user can select manually */
    }
  };

  const close = () => {
    onOpenChange(false);
    window.setTimeout(reset, 200);
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => (next ? onOpenChange(true) : close())}
    >
      <DialogContent>
        {created ? (
          <>
            <DialogHeader>
              <DialogTitle>Token created</DialogTitle>
              <DialogDescription>
                Copy it now — this is the only time the full token is shown.
                Madori stores only a hash and can&apos;t display it again.
              </DialogDescription>
            </DialogHeader>
            <div className="flex items-center gap-2 rounded-md border bg-muted/40 p-2">
              <code
                className="flex-1 truncate font-mono text-sm"
                data-testid="api-token-plaintext"
              >
                {created.plainToken}
              </code>
              <Button type="button" size="sm" variant="outline" onClick={() => void copyToken()}>
                {copied ? (
                  <>
                    <Check className="mr-1 h-3.5 w-3.5" /> Copied
                  </>
                ) : (
                  <>
                    <Copy className="mr-1 h-3.5 w-3.5" /> Copy
                  </>
                )}
              </Button>
            </div>
            <DialogFooter>
              <Button type="button" onClick={close} data-testid="api-token-done">
                Done
              </Button>
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>Generate a new token</DialogTitle>
              <DialogDescription>
                Tokens are shown once. Treat them like passwords.
              </DialogDescription>
            </DialogHeader>

            {error && (
              <Alert variant="destructive">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}

            <div className="space-y-1.5">
              <Label htmlFor="api-token-name">Name</Label>
              <Input
                id="api-token-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. ci-deploy-bot"
                maxLength={80}
                data-testid="api-token-name"
              />
            </div>

            <div className="space-y-1.5">
              <Label>Expiry</Label>
              <div className="flex flex-wrap gap-2">
                {EXPIRY_OPTIONS.map((o) => (
                  <button
                    key={o.value}
                    type="button"
                    onClick={() => setExpiry(o.value)}
                    className={cn(
                      "rounded-md border px-3 py-1.5 text-sm transition-colors",
                      expiry === o.value
                        ? "border-primary bg-primary/10"
                        : "border-input hover:bg-accent",
                    )}
                  >
                    {o.label}
                  </button>
                ))}
              </div>
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between gap-4">
                <div>
                  <Label htmlFor="api-token-restrict">Restrict permissions</Label>
                  <p className="text-xs text-muted-foreground">
                    Off = the token can do everything you can. On = limit it per
                    category.
                  </p>
                </div>
                <Switch
                  id="api-token-restrict"
                  checked={restricted}
                  onCheckedChange={setRestricted}
                  aria-label="Restrict token permissions"
                />
              </div>
              {restricted ? (
                <div className="rounded-md border p-3">
                  <PermissionTree
                    levels={LEVELS}
                    nodes={NODES}
                    values={access}
                    masterLabel="Token can access"
                    onChange={setAccess}
                  />
                </div>
              ) : null}
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={close} disabled={submitting}>
                Cancel
              </Button>
              <Button
                type="button"
                onClick={() => void submit()}
                disabled={submitting || name.trim() === ""}
                data-testid="api-token-create"
              >
                {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : "Generate token"}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
};

export default CreateApiTokenDialog;
