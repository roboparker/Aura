import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { Archive, Clock, Download } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";

interface ExportStatus {
  status: "ready" | "expired";
  spaceName: string;
  fileSize?: number | null;
  expiresAt?: string | null;
}

const formatBytes = (bytes: number): string => {
  if (bytes < 1024) return `${bytes} B`;
  const units = ["KB", "MB", "GB"];
  let value = bytes;
  let unit = "B";
  for (const next of units) {
    if (value < 1024) break;
    value /= 1024;
    unit = next;
  }
  return `${value.toFixed(value >= 10 ? 0 : 1)} ${unit}`;
};

/**
 * Landing page for the space-export email link. The API resolves the
 * token only for the signed-in requester, so an unauthenticated click
 * routes through sign-in and comes back here; anyone else's session
 * sees "not found". The Download button is a plain navigation to the
 * API's streaming endpoint (same origin, session cookie included).
 */
const ExportDownloadPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const { token } = router.query;
  const exportToken = typeof token === "string" ? token : null;

  const [status, setStatus] = useState<ExportStatus | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  useEffect(() => {
    if (!isAuthenticated || !exportToken) return;
    let cancelled = false;
    (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/space-exports/${encodeURIComponent(exportToken)}`,
          { credentials: "include", headers: { Accept: "application/json" } },
        );
        if (cancelled) return;
        if (res.status === 404) {
          setNotFound(true);
          return;
        }
        if (!res.ok) throw new Error("Failed to look up the export.");
        const data: ExportStatus = await res.json();
        setStatus(data);
      } catch (err) {
        if (!cancelled) {
          setError(
            err instanceof Error ? err.message : "Failed to look up the export.",
          );
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [isAuthenticated, exportToken]);

  if (authLoading || !isAuthenticated) {
    return (
      <div className="container mx-auto max-w-lg px-4 py-16 text-center text-muted-foreground">
        Loading…
      </div>
    );
  }

  let body;
  if (notFound) {
    body = (
      <>
        <h1 className="text-xl font-semibold">Export not found</h1>
        <p className="text-sm text-muted-foreground">
          This download link isn&apos;t valid for your account. Make sure
          you&apos;re signed in as the person who requested the export — or
          request a new one from the space&apos;s settings.
        </p>
      </>
    );
  } else if (error) {
    body = (
      <>
        <h1 className="text-xl font-semibold">Something went wrong</h1>
        <p className="text-sm text-muted-foreground">{error}</p>
      </>
    );
  } else if (status?.status === "expired") {
    body = (
      <>
        <Clock className="mx-auto h-10 w-10 text-muted-foreground" aria-hidden />
        <h1 className="text-xl font-semibold">This export has expired</h1>
        <p className="text-sm text-muted-foreground">
          Exports of <strong>{status.spaceName}</strong> are kept for 7 days,
          and this one has been deleted. You can request a fresh export from
          the space&apos;s settings.
        </p>
      </>
    );
  } else if (status?.status === "ready") {
    body = (
      <>
        <Archive className="mx-auto h-10 w-10 text-muted-foreground" aria-hidden />
        <h1 className="text-xl font-semibold">
          Your export of {status.spaceName} is ready
        </h1>
        <p className="text-sm text-muted-foreground">
          {typeof status.fileSize === "number" && status.fileSize > 0 && (
            <>Zip archive, {formatBytes(status.fileSize)}. </>
          )}
          {status.expiresAt && (
            <>
              Available until{" "}
              {new Date(status.expiresAt).toLocaleDateString(undefined, {
                dateStyle: "long",
              })}
              .
            </>
          )}
        </p>
        <Button asChild className="gap-1.5">
          <a
            href={`${ENTRYPOINT}/space-exports/${encodeURIComponent(exportToken ?? "")}/download`}
          >
            <Download className="h-4 w-4" aria-hidden />
            Download zip
          </a>
        </Button>
      </>
    );
  } else {
    body = (
      <p className="text-sm text-muted-foreground">Looking up your export…</p>
    );
  }

  return (
    <>
      <Head>
        <title>Space export - Aura</title>
      </Head>
      <div className="container mx-auto max-w-lg px-4 py-16">
        <Card>
          <CardContent className="pt-8 pb-8 space-y-4 text-center">
            {body}
            <p className="text-xs text-muted-foreground">
              <Link href="/spaces" className="underline underline-offset-2">
                Back to your spaces
              </Link>
            </p>
          </CardContent>
        </Card>
      </div>
    </>
  );
};

export default ExportDownloadPage;
