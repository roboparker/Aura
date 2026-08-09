import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { CheckCircle2, Clock, RotateCcw, TriangleAlert } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { pageTitle } from "@/lib/pageTitle";
import {
  NOUNS,
  formatPurgeDate,
  remainingLabel,
  type RestoreStatus,
  type RestoreTokenState,
} from "@/lib/deletionTypes";

/**
 * Landing page for the "…is scheduled for deletion" email's restore link.
 *
 * Deliberately usable **signed out**: an account inside its deletion grace
 * period can't sign in at all, so gating this behind auth would make the link
 * useless for the case that needs it most. The token in the URL is the
 * credential — the API treats it that way too.
 *
 * Every terminal state gets its own copy rather than a generic error, because
 * "already used", "expired", and "already permanently deleted" call for
 * completely different next steps.
 */
const RestorePage = () => {
  const router = useRouter();
  const { token } = router.query;
  const restoreToken = typeof token === "string" ? token : null;

  const [state, setState] = useState<RestoreTokenState | null>(null);
  const [status, setStatus] = useState<RestoreStatus | "unknown" | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!restoreToken) return;
    setLoading(true);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/restore/${encodeURIComponent(restoreToken)}`,
        { headers: { Accept: "application/json" } },
      );
      if (res.status === 404) {
        setStatus("unknown");
        return;
      }
      const data = (await res.json()) as RestoreTokenState;
      setState(data);
      setStatus(data.status);
    } catch {
      setStatus("unknown");
    } finally {
      setLoading(false);
    }
  }, [restoreToken]);

  useEffect(() => {
    if (!router.isReady) return;
    void load();
  }, [router.isReady, load]);

  const restore = async () => {
    if (!restoreToken) return;
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/restore/${encodeURIComponent(restoreToken)}`,
        { method: "POST", headers: { Accept: "application/json" } },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (typeof data.status === "string") setStatus(data.status as RestoreStatus);
        throw new Error(data.error || "Could not restore.");
      }
      setStatus("restored");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not restore.");
    } finally {
      setBusy(false);
    }
  };

  const noun = state ? NOUNS[state.targetType] : "item";
  const isAccount = state?.targetType === "account";

  const body = () => {
    if (loading || status === null) {
      return <p className="text-sm text-muted-foreground">Checking this link…</p>;
    }

    if (status === "unknown") {
      return (
        <Shell
          icon={<TriangleAlert className="h-6 w-6 text-muted-foreground" aria-hidden />}
          title="This restore link isn't valid"
        >
          <p className="text-sm text-muted-foreground">
            It may have been mistyped or truncated by your email client. Try
            copying the full link from the email and pasting it into your
            browser.
          </p>
        </Shell>
      );
    }

    if (status === "restored") {
      return (
        <Shell
          icon={<CheckCircle2 className="h-6 w-6 text-primary" aria-hidden />}
          title={`${state?.label ?? "It"} is back`}
        >
          <p className="text-sm text-muted-foreground">
            {isAccount
              ? "Your account has been restored — you can sign in again as normal."
              : `The ${noun} and everything in it is visible to its members again.`}
          </p>
          <Button asChild className="mt-4">
            <Link href={isAccount ? "/login" : "/"}>
              {isAccount ? "Sign in" : "Go to Madori"}
            </Link>
          </Button>
        </Shell>
      );
    }

    if (status === "used") {
      return (
        <Shell
          icon={<CheckCircle2 className="h-6 w-6 text-primary" aria-hidden />}
          title="Already restored"
        >
          <p className="text-sm text-muted-foreground">
            This link has already been used, so{" "}
            <span className="font-medium text-foreground">{state?.label}</span>{" "}
            is no longer scheduled for deletion. Nothing more to do.
          </p>
          <Button asChild variant="outline" className="mt-4">
            <Link href={isAccount ? "/login" : "/"}>
              {isAccount ? "Sign in" : "Go to Madori"}
            </Link>
          </Button>
        </Shell>
      );
    }

    if (status === "active") {
      return (
        <Shell
          icon={<CheckCircle2 className="h-6 w-6 text-primary" aria-hidden />}
          title="Nothing to restore"
        >
          <p className="text-sm text-muted-foreground">
            <span className="font-medium text-foreground">{state?.label}</span>{" "}
            isn&apos;t scheduled for deletion — someone has already cancelled it.
          </p>
        </Shell>
      );
    }

    if (status === "expired" || status === "gone") {
      return (
        <Shell
          icon={<Clock className="h-6 w-6 text-destructive" aria-hidden />}
          title="This link has expired"
        >
          <p className="text-sm text-muted-foreground">
            The restore window for{" "}
            <span className="font-medium text-foreground">{state?.label}</span>{" "}
            has passed
            {state?.expiresAt ? ` (${formatPurgeDate(state.expiresAt)})` : ""}, so
            it has been permanently deleted. That can&apos;t be undone.
          </p>
          <p className="mt-3 text-sm text-muted-foreground">
            If you asked for a data export before deleting, the download link was
            emailed to you separately.
          </p>
        </Shell>
      );
    }

    // status === "ready"
    return (
      <Shell
        icon={<RotateCcw className="h-6 w-6 text-primary" aria-hidden />}
        title={`Restore ${state?.label ?? `this ${noun}`}?`}
      >
        <p className="text-sm text-muted-foreground">
          {isAccount
            ? "Your account is scheduled for deletion. Restoring it cancels that and lets you sign in again."
            : `This ${noun} is scheduled for deletion. Restoring it makes it visible to its members again.`}
        </p>
        {state?.expiresAt && (
          <p className="mt-3 text-sm text-muted-foreground">
            {remainingLabel(state.expiresAt)} — after{" "}
            {formatPurgeDate(state.expiresAt)} this can&apos;t be undone.
          </p>
        )}
        {error && (
          <Alert variant="destructive" className="mt-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        <Button className="mt-4" onClick={restore} disabled={busy}>
          {busy ? "Restoring…" : `Restore ${isAccount ? "my account" : `this ${noun}`}`}
        </Button>
      </Shell>
    );
  };

  return (
    <>
      <Head>
        <title>{pageTitle("Restore")}</title>
        {/* A restore link is single-use and personal — keep it out of indexes. */}
        <meta name="robots" content="noindex" />
      </Head>
      <main className="mx-auto flex min-h-screen w-full max-w-lg items-center px-4 py-12">
        <Card className="w-full">
          <CardContent className="p-6">{body()}</CardContent>
        </Card>
      </main>
    </>
  );
};

const Shell = ({
  icon,
  title,
  children,
}: {
  icon: React.ReactNode;
  title: string;
  children: React.ReactNode;
}) => (
  <div>
    <div className="mb-3 flex items-center gap-3">
      <span className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-muted">
        {icon}
      </span>
      <h1 className="text-lg font-semibold">{title}</h1>
    </div>
    {children}
  </div>
);

export default RestorePage;
