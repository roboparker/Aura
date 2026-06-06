import { useCallback, useEffect, useState } from "react";
import { Loader2, Monitor } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { useAuth } from "@/contexts/AuthContext";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

interface SessionRow {
  id: string;
  current: boolean;
  device: { browser: string; os: string; label: string };
  ipAddress: string | null;
  createdAt: string;
  lastSeenAt: string;
}

const formatRelative = (iso: string): string => {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "—";
  const diffMs = Date.now() - then;
  const mins = Math.round(diffMs / 60000);
  if (mins < 1) return "just now";
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.round(hrs / 24);
  if (days < 30) return `${days}d ago`;
  return new Date(iso).toLocaleDateString();
};

/**
 * Active sessions list for the Settings security panel. Self-fetches
 * `/me/sessions`; supports per-session revoke and a step-up–protected
 * "sign out everywhere else".
 */
const ActiveSessionsTable = () => {
  const { user } = useAuth();
  const [sessions, setSessions] = useState<SessionRow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [revokingId, setRevokingId] = useState<string | null>(null);
  const [stepUpOpen, setStepUpOpen] = useState(false);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/me/sessions`, {
        credentials: "include",
        headers: { Accept: "application/json" },
      });
      if (!res.ok) throw new Error("Failed to load sessions.");
      const data = (await res.json()) as { sessions?: SessionRow[] };
      setSessions(data.sessions ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load sessions.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const revoke = async (id: string) => {
    setRevokingId(id);
    try {
      const res = await fetch(`${ENTRYPOINT}/me/sessions/${id}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) throw new Error("Failed to revoke session.");
      setSessions((prev) => prev.filter((s) => s.id !== id));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to revoke session.");
    } finally {
      setRevokingId(null);
    }
  };

  return (
    <div className="space-y-3" data-testid="active-sessions">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          Devices currently signed in to your account.
        </p>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setStepUpOpen(true)}
          disabled={sessions.length <= 1}
          data-testid="sessions-revoke-others"
        >
          Sign out everywhere else
        </Button>
      </div>

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (
        <div className="rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Device</TableHead>
                <TableHead>IP</TableHead>
                <TableHead>Last active</TableHead>
                <TableHead className="text-right">Action</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {sessions.map((s) => (
                <TableRow key={s.id} data-testid="session-row">
                  <TableCell>
                    <div className="flex items-center gap-2">
                      <Monitor className="h-4 w-4 text-muted-foreground" />
                      <span className="font-medium">{s.device.label}</span>
                      {s.current && (
                        <Badge variant="secondary" data-testid="session-current">
                          This device
                        </Badge>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="text-muted-foreground tabular-nums">
                    {s.ipAddress ?? "—"}
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {formatRelative(s.lastSeenAt)}
                  </TableCell>
                  <TableCell className="text-right">
                    {s.current ? (
                      <span className="text-xs text-muted-foreground">—</span>
                    ) : (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-destructive hover:text-destructive"
                        disabled={revokingId === s.id}
                        onClick={() => void revoke(s.id)}
                        data-testid="session-revoke"
                      >
                        Revoke
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      <RevokeOthersDialog
        open={stepUpOpen}
        onOpenChange={setStepUpOpen}
        twoFactorEnabled={Boolean(user?.twoFactor.enabled)}
        onDone={() => {
          setStepUpOpen(false);
          void load();
        }}
      />
    </div>
  );
};

interface RevokeOthersDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  twoFactorEnabled: boolean;
  onDone: () => void;
}

const RevokeOthersDialog = ({
  open,
  onOpenChange,
  twoFactorEnabled,
  onDone,
}: RevokeOthersDialogProps) => {
  const [value, setValue] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setValue("");
      setError(null);
    }
  }, [open]);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      const body = twoFactorEnabled
        ? { totpCode: value }
        : { currentPassword: value };
      const res = await fetch(`${ENTRYPOINT}/me/sessions/revoke-others`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to sign out other sessions.");
      }
      onDone();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Sign out everywhere else</DialogTitle>
          <DialogDescription>
            This revokes every other signed-in session.{" "}
            {twoFactorEnabled
              ? "Enter a code from your authenticator to confirm."
              : "Enter your current password to confirm."}
          </DialogDescription>
        </DialogHeader>
        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        <div className="space-y-1.5">
          <Label htmlFor="sessions-stepup">
            {twoFactorEnabled ? "Authentication code" : "Current password"}
          </Label>
          <Input
            id="sessions-stepup"
            type={twoFactorEnabled ? "text" : "password"}
            inputMode={twoFactorEnabled ? "numeric" : undefined}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            autoComplete={twoFactorEnabled ? "one-time-code" : "current-password"}
            data-testid="sessions-stepup-input"
          />
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
            onClick={() => void submit()}
            disabled={submitting || value.trim() === ""}
            data-testid="sessions-stepup-submit"
          >
            {submitting ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              "Sign out others"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};

export default ActiveSessionsTable;
