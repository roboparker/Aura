import type { NextPage } from "next";
import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";

interface FieldSpec {
  key: string;
  label: string;
  placeholder?: string;
}
interface ProviderSpec {
  slug: string;
  label: string;
  /** The secret field name in the API (default clientSecret; Apple uses key). */
  secretKey: string;
  secretLabel: string;
  secretMultiline?: boolean;
  fields: FieldSpec[];
}

const PROVIDERS: ProviderSpec[] = [
  { slug: "google", label: "Google", secretKey: "clientSecret", secretLabel: "Client secret", fields: [] },
  {
    slug: "microsoft",
    label: "Microsoft",
    secretKey: "clientSecret",
    secretLabel: "Client secret",
    fields: [{ key: "tenant", label: "Tenant", placeholder: "common" }],
  },
  { slug: "github", label: "GitHub", secretKey: "clientSecret", secretLabel: "Client secret", fields: [] },
  { slug: "facebook", label: "Facebook", secretKey: "clientSecret", secretLabel: "Client secret", fields: [] },
  {
    slug: "apple",
    label: "Apple",
    secretKey: "key",
    secretLabel: "Signing key (.p8 contents)",
    secretMultiline: true,
    fields: [
      { key: "teamId", label: "Team ID" },
      { key: "keyId", label: "Key ID" },
    ],
  },
  {
    slug: "okta",
    label: "Okta",
    secretKey: "clientSecret",
    secretLabel: "Client secret",
    fields: [{ key: "issuer", label: "Issuer URL", placeholder: "https://your-org.okta.com" }],
  },
  {
    slug: "auth0",
    label: "Auth0",
    secretKey: "clientSecret",
    secretLabel: "Client secret",
    fields: [{ key: "issuer", label: "Issuer URL", placeholder: "https://your-tenant.auth0.com" }],
  },
];

type Redacted = Record<string, unknown>;

const ProviderCard = ({ spec, config }: { spec: ProviderSpec; config: Redacted }) => {
  const str = (k: string) => (typeof config[k] === "string" ? (config[k] as string) : "");
  const hasSecretFlag = "has" + spec.secretKey.charAt(0).toUpperCase() + spec.secretKey.slice(1);

  const [enabled, setEnabled] = useState(Boolean(config.enabled));
  const [clientId, setClientId] = useState(str("clientId"));
  const [fields, setFields] = useState<Record<string, string>>(
    Object.fromEntries(spec.fields.map((f) => [f.key, str(f.key)])),
  );
  const [secret, setSecret] = useState("");
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const hasSecret = Boolean(config[hasSecretFlag]);

  const callbackUrl =
    typeof window !== "undefined" ? `${window.location.origin}/auth/sso/${spec.slug}/check` : "";

  const save = async () => {
    setSaving(true);
    setMsg(null);
    try {
      const body: Record<string, unknown> = { enabled, clientId, ...fields };
      if (secret.trim() !== "") body[spec.secretKey] = secret.trim();
      const res = await fetch(`${ENTRYPOINT}/admin/sso/${spec.slug}`, {
        method: "PUT",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      if (!res.ok) throw new Error("Save failed.");
      setSecret("");
      setMsg({ text: "Saved.", ok: true });
    } catch (e) {
      setMsg({ text: e instanceof Error ? e.message : "Save failed.", ok: false });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card data-testid={`sso-provider-${spec.slug}`}>
      <CardHeader className="flex flex-row items-center justify-between gap-2">
        <CardTitle>{spec.label}</CardTitle>
        <label className="flex items-center gap-2 text-sm text-muted-foreground">
          Enabled
          <Switch checked={enabled} onCheckedChange={setEnabled} aria-label={`Enable ${spec.label}`} />
        </label>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="space-y-1.5">
          <Label>Redirect / callback URL (register this with {spec.label})</Label>
          <Input value={callbackUrl} readOnly className="text-xs text-muted-foreground" />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={`${spec.slug}-clientId`}>{spec.slug === "apple" ? "Services ID" : "Client ID"}</Label>
          <Input
            id={`${spec.slug}-clientId`}
            value={clientId}
            onChange={(e) => setClientId(e.target.value)}
          />
        </div>
        {spec.fields.map((f) => (
          <div key={f.key} className="space-y-1.5">
            <Label htmlFor={`${spec.slug}-${f.key}`}>{f.label}</Label>
            <Input
              id={`${spec.slug}-${f.key}`}
              value={fields[f.key] ?? ""}
              placeholder={f.placeholder}
              onChange={(e) => setFields((prev) => ({ ...prev, [f.key]: e.target.value }))}
            />
          </div>
        ))}
        <div className="space-y-1.5">
          <Label htmlFor={`${spec.slug}-secret`}>
            {spec.secretLabel}{" "}
            {hasSecret && <span className="text-xs text-emerald-500">(configured — leave blank to keep)</span>}
          </Label>
          {spec.secretMultiline ? (
            <Textarea
              id={`${spec.slug}-secret`}
              value={secret}
              onChange={(e) => setSecret(e.target.value)}
              placeholder={hasSecret ? "•••••• stored" : "-----BEGIN PRIVATE KEY-----"}
              rows={4}
            />
          ) : (
            <Input
              id={`${spec.slug}-secret`}
              type="password"
              value={secret}
              onChange={(e) => setSecret(e.target.value)}
              placeholder={hasSecret ? "•••••• stored" : ""}
            />
          )}
        </div>
        <div className="flex items-center gap-3">
          <Button type="button" size="sm" onClick={() => void save()} disabled={saving}>
            {saving ? "Saving…" : "Save"}
          </Button>
          {msg && (
            <span className={msg.ok ? "text-xs text-muted-foreground" : "text-xs text-destructive"}>
              {msg.text}
            </span>
          )}
        </div>
      </CardContent>
    </Card>
  );
};

const SsoAdminPage: NextPage = () => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");
  const [configs, setConfigs] = useState<Record<string, Redacted> | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    try {
      const res = await fetch(`${ENTRYPOINT}/admin/sso`, { credentials: "include" });
      if (!res.ok) throw new Error("Couldn't load SSO settings.");
      const data: { providers?: Redacted[] } = await res.json();
      const map: Record<string, Redacted> = {};
      for (const p of data.providers ?? []) {
        if (typeof p.provider === "string") map[p.provider] = p;
      }
      setConfigs(map);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Couldn't load SSO settings.");
    }
  }, []);

  useEffect(() => {
    if (isAdmin) void load();
  }, [isAdmin, load]);

  if (isLoading || !isAuthenticated) return <div className="min-h-screen bg-background" />;
  if (!isAdmin) {
    return (
      <div className="min-h-screen bg-background px-4 py-12">
        <p className="mx-auto max-w-5xl text-sm text-muted-foreground">Admins only.</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>SSO - Madori Admin</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-5xl space-y-6">
          <header>
            <h1 className="text-2xl font-bold">Single sign-on</h1>
            <p className="text-sm text-muted-foreground">
              Configure social &amp; enterprise sign-in providers. A provider&apos;s button appears on
              sign-in once it&apos;s enabled and fully configured. Secrets are stored encrypted and never
              shown again.
            </p>
          </header>

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {configs === null && !error ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : (
            <div className="space-y-4">
              {PROVIDERS.map((spec) => (
                <ProviderCard key={spec.slug} spec={spec} config={configs?.[spec.slug] ?? {}} />
              ))}
            </div>
          )}
        </div>
      </div>
    </>
  );
};

export default SsoAdminPage;
