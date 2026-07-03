import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/router";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription } from "@/components/ui/alert";

const LABELS: Record<string, string> = { google: "Google", microsoft: "Microsoft" };
const ORDER = ["google", "microsoft"];

interface Conn {
  provider: string;
  email: string | null;
  scopes: string[];
  connectedAt: string;
}
interface Data {
  connections: Conn[];
  available: string[];
}

/**
 * Connect a Google/Microsoft account for calendar access (#581). These are
 * data-access connections (calendar scopes + a refresh token), distinct from
 * SSO sign-in. Availability follows the admin-configured provider apps.
 */
const ConnectedApps = () => {
  const router = useRouter();
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetch(`${ENTRYPOINT}/me/connections`, {
        credentials: "include",
        headers: { Accept: "application/json" },
      });
      if (!res.ok) throw new Error("Couldn't load connections.");
      setData((await res.json()) as Data);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Couldn't load connections.");
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const disconnect = async (provider: string) => {
    setBusy(provider);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/me/connections/${provider}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) throw new Error("Failed to disconnect.");
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to disconnect.");
    } finally {
      setBusy(null);
    }
  };

  const connected = typeof router.query.connected === "string" ? router.query.connected : null;
  const qErr = typeof router.query.error === "string" ? router.query.error : null;

  if (!data) {
    return <p className="text-sm text-muted-foreground">{error ?? "Loading…"}</p>;
  }

  const byProvider = new Map(data.connections.map((c) => [c.provider, c] as const));
  const ordered = ORDER.filter((p) => data.available.includes(p));

  return (
    <div className="space-y-3" data-testid="connected-apps">
      <div>
        <h3 className="text-sm font-medium">Connected calendar accounts</h3>
        <p className="text-xs text-muted-foreground">
          Connect Google or Microsoft to enable two-way calendar sync.
        </p>
      </div>

      {connected && (
        <Alert>
          <AlertDescription>Connected {LABELS[connected] ?? connected}.</AlertDescription>
        </Alert>
      )}
      {qErr && (
        <Alert variant="destructive">
          <AlertDescription>Couldn&apos;t connect the account ({qErr}).</AlertDescription>
        </Alert>
      )}
      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {ordered.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          No providers are available. An admin can configure Google/Microsoft under Admin → SSO to
          enable calendar connections.
        </p>
      ) : (
        <ul className="divide-y rounded-md border">
          {ordered.map((p) => {
            const conn = byProvider.get(p);
            return (
              <li key={p} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                <div className="min-w-0">
                  <div className="font-medium">{LABELS[p] ?? p}</div>
                  <div className="truncate text-xs text-muted-foreground">
                    {conn ? (conn.email ?? "Connected") : "Not connected"}
                  </div>
                </div>
                {conn ? (
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={busy === p}
                    onClick={() => void disconnect(p)}
                    className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                  >
                    {busy === p ? "Removing…" : "Disconnect"}
                  </Button>
                ) : (
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      window.location.href = `${ENTRYPOINT}/me/connections/${p}/connect`;
                    }}
                  >
                    Connect
                  </Button>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
};

export default ConnectedApps;
