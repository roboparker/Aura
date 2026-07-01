import type { NextPage } from "next";
import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { fetchWithTimeout } from "@/lib/fetchWithTimeout";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Switch } from "@/components/ui/switch";

interface WaitlistUser {
  id: string;
  email: string;
  givenName: string;
  familyName: string;
}

interface WaitlistState {
  waitlistEnabled: boolean;
  waitlistedCount: number;
  users: WaitlistUser[];
}

const fullName = (u: WaitlistUser): string =>
  `${u.givenName} ${u.familyName}`.trim() || u.email;

const AdminWaitlist: NextPage = () => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");

  const [state, setState] = useState<WaitlistState | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  // Id of the user whose per-row "Grant access" request is in flight.
  const [promotingId, setPromotingId] = useState<string | null>(null);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/admin/waitlist`, {
        credentials: "include",
      });
      if (!res.ok) {
        setError("Couldn't load the waitlist settings.");
        return;
      }
      const data = await res.json();
      setState({
        waitlistEnabled: Boolean(data.waitlistEnabled),
        waitlistedCount: Number(data.waitlistedCount ?? 0),
        users: Array.isArray(data.users) ? (data.users as WaitlistUser[]) : [],
      });
    } catch {
      setError("Couldn't load the waitlist settings.");
    }
  }, []);

  useEffect(() => {
    if (isAdmin) load();
  }, [isAdmin, load]);

  const persist = useCallback(async (enabled: boolean) => {
    setSaving(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/admin/waitlist`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ waitlistEnabled: enabled }),
      });
      if (!res.ok) {
        setError("Couldn't update waitlist mode.");
        return;
      }
      const data = await res.json();
      const promoted = Number(data.promotedCount ?? 0);
      setState((prev) => ({
        waitlistEnabled: Boolean(data.waitlistEnabled),
        // Opening signups empties the waitlist; closing it leaves the count as-is.
        waitlistedCount: enabled ? prev?.waitlistedCount ?? 0 : 0,
        users: enabled ? prev?.users ?? [] : [],
      }));
      if (enabled) {
        setNotice("Waitlist mode is on. New sign-ups will join the waitlist.");
      } else if (promoted > 0) {
        setNotice(
          `Signups are open. Granted access to ${promoted} waitlisted ${
            promoted === 1 ? "person" : "people"
          } and emailed them.`,
        );
      } else {
        setNotice("Signups are open.");
      }
    } catch {
      setError("Couldn't update waitlist mode.");
    } finally {
      setSaving(false);
    }
  }, []);

  const promoteUser = useCallback(async (target: WaitlistUser) => {
    setPromotingId(target.id);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/admin/waitlist/promote`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ userId: target.id }),
      });
      if (!res.ok) {
        setError(`Couldn't grant access to ${target.email}.`);
        return;
      }
      const data = await res.json();
      setState((prev) =>
        prev
          ? {
              ...prev,
              waitlistedCount: Number(data.waitlistedCount ?? prev.waitlistedCount),
              users: prev.users.filter((u) => u.id !== target.id),
            }
          : prev,
      );
      setNotice(`Granted access to ${fullName(target)} and emailed them.`);
    } catch {
      setError(`Couldn't grant access to ${target.email}.`);
    } finally {
      setPromotingId(null);
    }
  }, []);

  const handleToggle = (next: boolean) => {
    if (!state) return;
    // Turning the waitlist OFF releases everyone who's waiting (promotes +
    // emails them) — confirm first when there are people on the list.
    if (!next && state.waitlistedCount > 0) {
      setConfirmOpen(true);
      return;
    }
    persist(next);
  };

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (!isAuthenticated) {
    return null;
  }

  if (!isAdmin) {
    return (
      <>
        <Head>
          <title>Access Denied - Madori</title>
        </Head>
        <div className="min-h-screen flex items-center justify-center bg-muted px-4">
          <div className="text-center">
            <h1 className="text-2xl font-bold text-foreground mb-2">Access Denied</h1>
            <p className="text-muted-foreground">
              You need administrator privileges to view this page.
            </p>
          </div>
        </div>
      </>
    );
  }

  const count = state?.waitlistedCount ?? 0;

  return (
    <>
      <Head>
        <title>Waitlist - Madori Admin</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="max-w-2xl mx-auto space-y-6">
          <header>
            <h1 className="text-2xl font-bold">Waitlist</h1>
            <p className="text-sm text-muted-foreground">
              Control whether new people get immediate access or join a waitlist.
            </p>
          </header>

          {error && (
            <Alert variant="destructive" data-testid="waitlist-error">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          {notice && (
            <Alert data-testid="waitlist-notice">
              <AlertDescription>{notice}</AlertDescription>
            </Alert>
          )}

          <Card data-testid="waitlist-card">
            <CardHeader>
              <CardTitle>Waitlist mode</CardTitle>
              <CardDescription>
                When on, new sign-ups join the waitlist and can&apos;t use Madori
                until you open signups. Turning it off grants access to everyone
                waiting and emails them.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between gap-4 rounded-md border p-4">
                <div>
                  <p className="text-sm font-medium">
                    {state?.waitlistEnabled ? "Waitlist mode is on" : "Signups are open"}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {count === 1
                      ? "1 person is waiting."
                      : `${count} people are waiting.`}
                  </p>
                </div>
                <Switch
                  checked={state?.waitlistEnabled ?? false}
                  onCheckedChange={handleToggle}
                  disabled={!state || saving}
                  aria-label="Waitlist mode"
                  data-testid="waitlist-toggle"
                />
              </div>
            </CardContent>
          </Card>

          {state && state.users.length > 0 && (
            <Card data-testid="waitlist-people">
              <CardHeader>
                <CardTitle>People waiting</CardTitle>
                <CardDescription>
                  Grant access to individual people without opening signups to
                  everyone. They&apos;ll be emailed and can sign in right away.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-2">
                {state.users.map((u) => (
                  <div
                    key={u.id}
                    className="flex items-center justify-between gap-4 rounded-md border p-3"
                    data-testid="waitlist-person"
                  >
                    <div className="min-w-0">
                      <p className="text-sm font-medium truncate">{fullName(u)}</p>
                      <p className="text-xs text-muted-foreground truncate">{u.email}</p>
                    </div>
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={promotingId !== null}
                      onClick={() => promoteUser(u)}
                      data-testid="waitlist-grant"
                    >
                      {promotingId === u.id ? "Granting…" : "Grant access"}
                    </Button>
                  </div>
                ))}
                {count > state.users.length && (
                  <p className="pt-1 text-xs text-muted-foreground">
                    Showing the first {state.users.length} of {count}.
                  </p>
                )}
              </CardContent>
            </Card>
          )}
        </div>
      </div>

      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Open signups?</DialogTitle>
            <DialogDescription>
              This grants access to {count} waitlisted{" "}
              {count === 1 ? "person" : "people"} right now and emails them that
              their account is ready. They&apos;ll be able to sign in
              immediately.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="ghost"
              onClick={() => setConfirmOpen(false)}
              disabled={saving}
            >
              Cancel
            </Button>
            <Button
              onClick={() => {
                setConfirmOpen(false);
                persist(false);
              }}
              disabled={saving}
              data-testid="waitlist-confirm-open"
            >
              Open signups
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
};

export default AdminWaitlist;
