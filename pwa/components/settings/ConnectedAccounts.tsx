import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/router";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription } from "@/components/ui/alert";

const LABELS: Record<string, string> = {
  google: "Google",
  microsoft: "Microsoft",
  apple: "Apple",
  facebook: "Facebook",
  github: "GitHub",
  okta: "Okta",
  auth0: "Auth0",
};
const ORDER = ["google", "microsoft", "apple", "facebook", "github", "okta", "auth0"];

interface Identity {
  provider: string;
  email: string | null;
  createdAt: string;
}
interface Data {
  identities: Identity[];
  available: string[];
  hasPassword: boolean;
}

/**
 * Manage social sign-in connections (#267): connect/disconnect Google,
 * Microsoft, GitHub for the signed-in user. Connecting redirects through the
 * OAuth link flow; the callback returns here with a `?sso_*` result.
 */
const ConnectedAccounts = () => {
  const router = useRouter();
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetch(`${ENTRYPOINT}/me/sso/identities`, {
        credentials: "include",
        headers: { Accept: "application/json" },
      });
      if (!res.ok) throw new Error("Failed to load connected accounts.");
      setData((await res.json()) as Data);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to load.");
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const disconnect = async (provider: string) => {
    setBusy(provider);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/me/sso/${provider}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) {
        const body: { error?: string } = await res.json().catch(() => ({}));
        throw new Error(body.error ?? "Failed to disconnect.");
      }
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Failed to disconnect.");
    } finally {
      setBusy(null);
    }
  };

  const linkedOk = typeof router.query.sso_linked === "string" ? router.query.sso_linked : null;
  const already =
    typeof router.query.sso_already_linked === "string" ? router.query.sso_already_linked : null;
  const ssoErr = typeof router.query.sso_error === "string" ? router.query.sso_error : null;

  if (!data) {
    return <p className="text-sm text-muted-foreground">{error ?? "Loading…"}</p>;
  }
  if (data.available.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        No social sign-in providers are configured on this instance.
      </p>
    );
  }

  const linked = new Map(data.identities.map((i) => [i.provider, i] as const));
  const ordered = ORDER.filter((p) => data.available.includes(p));

  return (
    <div className="space-y-3" data-testid="connected-accounts">
      {linkedOk && (
        <Alert>
          <AlertDescription>Connected {LABELS[linkedOk] ?? linkedOk}.</AlertDescription>
        </Alert>
      )}
      {already && (
        <Alert variant="destructive">
          <AlertDescription>
            That {LABELS[already] ?? already} account is already linked to a different user.
          </AlertDescription>
        </Alert>
      )}
      {ssoErr && (
        <Alert variant="destructive">
          <AlertDescription>Couldn&apos;t connect the account ({ssoErr}).</AlertDescription>
        </Alert>
      )}
      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <ul className="divide-y rounded-md border">
        {ordered.map((p) => {
          const id = linked.get(p);
          return (
            <li key={p} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
              <div className="min-w-0">
                <div className="font-medium">{LABELS[p] ?? p}</div>
                <div className="truncate text-xs text-muted-foreground">
                  {id ? (id.email ?? "Connected") : "Not connected"}
                </div>
              </div>
              {id ? (
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
                    window.location.href = `${ENTRYPOINT}/me/sso/${p}/connect`;
                  }}
                >
                  Connect
                </Button>
              )}
            </li>
          );
        })}
      </ul>

      {!data.hasPassword && (
        <p className="text-xs text-muted-foreground">
          Set a password to keep access if you disconnect your only sign-in method.
        </p>
      )}
    </div>
  );
};

export default ConnectedAccounts;
