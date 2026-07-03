import { useEffect, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";

/** Display names + stable order for the built-in providers. */
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

interface Props {
  /** Where to land after a successful sign-in (login flow only). */
  next?: string;
  /** Button verb, e.g. "Sign in" / "Sign up". */
  verb?: string;
}

/**
 * Social sign-in buttons (#267). Renders a "Continue with X" button for each
 * provider the instance has configured (from the public status endpoint), or
 * nothing when none are set up. Clicking navigates to the OAuth start endpoint.
 */
const SsoButtons = ({ next, verb = "Continue" }: Props) => {
  const [providers, setProviders] = useState<string[] | null>(null);

  useEffect(() => {
    void fetch(`${ENTRYPOINT}/auth/sso/status`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((r) => (r.ok ? r.json() : { providers: [] }))
      .then((d: { providers?: unknown }) =>
        setProviders(Array.isArray(d.providers) ? (d.providers as string[]) : []),
      )
      .catch(() => setProviders([]));
  }, []);

  if (!providers || providers.length === 0) return null;
  const ordered = ORDER.filter((p) => providers.includes(p));
  if (ordered.length === 0) return null;

  return (
    <div className="space-y-2" data-testid="sso-buttons">
      <div className="flex items-center gap-2 py-1 text-xs text-muted-foreground">
        <span className="h-px flex-1 bg-border" />
        or
        <span className="h-px flex-1 bg-border" />
      </div>
      {ordered.map((provider) => (
        <Button
          key={provider}
          type="button"
          variant="outline"
          className="w-full"
          onClick={() => {
            const q = next ? `?next=${encodeURIComponent(next)}` : "";
            window.location.href = `${ENTRYPOINT}/auth/sso/${provider}/start${q}`;
          }}
          data-testid={`sso-${provider}`}
        >
          {verb} with {LABELS[provider] ?? provider}
        </Button>
      ))}
    </div>
  );
};

export default SsoButtons;
