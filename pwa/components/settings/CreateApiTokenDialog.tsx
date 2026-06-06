import { useState } from "react";
import { Check, Copy, Loader2 } from "lucide-react";
import {
  API_SCOPES,
  EXPIRY_OPTIONS,
  createApiToken,
  expiryIsoFromDays,
  type CreatedApiToken,
} from "@/lib/apiTokens";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
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
import { cn } from "@/lib/utils";

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
  const [scopes, setScopes] = useState<string[]>(["read:tasks"]);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [created, setCreated] = useState<CreatedApiToken | null>(null);
  const [copied, setCopied] = useState(false);

  const reset = () => {
    setName("");
    setExpiry("90");
    setScopes(["read:tasks"]);
    setError(null);
    setCreated(null);
    setCopied(false);
  };

  const toggleScope = (value: string) =>
    setScopes((prev) =>
      prev.includes(value) ? prev.filter((s) => s !== value) : [...prev, value],
    );

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      const option = EXPIRY_OPTIONS.find((o) => o.value === expiry);
      const token = await createApiToken({
        name: name.trim(),
        scopes,
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
    // Reset after the close animation so the form is fresh next open.
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
                Aura stores only a hash and can&apos;t display it again.
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
              <Label>Scopes</Label>
              <div className="space-y-2">
                {API_SCOPES.map((s) => (
                  <label
                    key={s.value}
                    className="flex items-start gap-2 rounded-md border border-input p-2 text-sm"
                  >
                    <Checkbox
                      checked={scopes.includes(s.value)}
                      onCheckedChange={() => toggleScope(s.value)}
                      data-testid={`api-token-scope-${s.value}`}
                    />
                    <span>
                      <span className="font-mono font-medium">{s.label}</span>
                      <span className="block text-xs text-muted-foreground">
                        {s.description}
                      </span>
                    </span>
                  </label>
                ))}
              </div>
              <p className="text-xs text-muted-foreground">
                Leave all unchecked for an unrestricted token.
              </p>
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
