import type { NextPage } from "next";
import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
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

// Admin-only page to verify Sentry captures errors on each surface once the
// DSNs are configured (see docs/developer/monitoring.md). Nothing here is
// destructive — every action just produces a deliberate error.
const AdminSentryTest: NextPage = () => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");

  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<"api" | "worker" | null>(null);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  // Throw an uncaught client-side error. Deferred via setTimeout so it reaches
  // the browser's global error handler (which Sentry hooks) rather than being
  // swallowed by a surrounding try/catch.
  const throwClientError = () => {
    setError(null);
    setNotice(
      "Threw a client-side error. If a DSN is configured it should appear in the Next.js Sentry project shortly (also visible in the browser console).",
    );
    setTimeout(() => {
      throw new Error("Sentry client test error — triggered from the admin verification page.");
    }, 0);
  };

  const triggerApiError = async () => {
    setBusy("api");
    setError(null);
    setNotice(null);
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/admin/sentry-test/error`, {
        credentials: "include",
      });
      // The endpoint throws server-side, so a 500 is the expected, successful
      // outcome of this test.
      if (res.status === 500) {
        setNotice(
          "Triggered a server error (HTTP 500). If a DSN is configured it should appear in the Sentry PHP project shortly.",
        );
      } else {
        setError(`Unexpected response from the API test endpoint (HTTP ${res.status}).`);
      }
    } catch {
      setError("Couldn't reach the API test endpoint.");
    } finally {
      setBusy(null);
    }
  };

  const triggerWorkerError = async () => {
    setBusy("worker");
    setError(null);
    setNotice(null);
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/admin/sentry-test/worker`, {
        method: "POST",
        credentials: "include",
      });
      if (res.status === 202) {
        setNotice(
          "Queued a job that fails on purpose. If a DSN is configured the failure should appear in the Sentry PHP project once the worker runs it (it also lands in the failed queue for inspection).",
        );
      } else {
        setError(`Unexpected response from the worker test endpoint (HTTP ${res.status}).`);
      }
    } catch {
      setError("Couldn't reach the worker test endpoint.");
    } finally {
      setBusy(null);
    }
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

  return (
    <>
      <Head>
        <title>Sentry test - Madori Admin</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="max-w-3xl mx-auto space-y-6">
          <header>
            <h1 className="text-2xl font-bold">Sentry test</h1>
            <p className="text-sm text-muted-foreground">
              Verify that errors reach Sentry on each surface. These triggers are
              safe — they touch no data. Nothing is sent until the corresponding
              DSN is configured.
            </p>
          </header>

          {error && (
            <Alert variant="destructive" data-testid="sentry-test-error">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          {notice && (
            <Alert data-testid="sentry-test-notice">
              <AlertDescription>{notice}</AlertDescription>
            </Alert>
          )}

          <Card>
            <CardHeader>
              <CardTitle>Frontend (PWA)</CardTitle>
              <CardDescription>
                Throws an uncaught error in the browser to test the Next.js
                Sentry project.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Button
                variant="outline"
                onClick={throwClientError}
                data-testid="sentry-test-client"
              >
                Throw a client error
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>API (Symfony)</CardTitle>
              <CardDescription>
                Calls an endpoint that raises an exception (HTTP 500) to test the
                Sentry PHP project.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Button
                variant="outline"
                onClick={triggerApiError}
                disabled={busy !== null}
                data-testid="sentry-test-api"
              >
                {busy === "api" ? "Triggering…" : "Trigger an API error"}
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Worker (background job)</CardTitle>
              <CardDescription>
                Queues a job that fails on purpose to test worker-side capture
                (same Sentry PHP project). The failed job dead-letters for
                inspection.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Button
                variant="outline"
                onClick={triggerWorkerError}
                disabled={busy !== null}
                data-testid="sentry-test-worker"
              >
                {busy === "worker" ? "Queuing…" : "Trigger a worker error"}
              </Button>
            </CardContent>
          </Card>
        </div>
      </div>
    </>
  );
};

export default AdminSentryTest;
