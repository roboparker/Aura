import type { NextPage } from "next";
import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useRef, useState } from "react";
import { CheckCircle2, Loader2, MailCheck, XCircle } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { fetchWithTimeout } from "@/lib/fetchWithTimeout";
import MadoriWordmark from "@/components/auth/MadoriWordmark";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { pageTitle } from "@/lib/pageTitle";

type ConfirmStatus = "verifying" | "success" | "error";
type ResendStatus = "idle" | "sending" | "sent" | "error";

/**
 * Two screens behind one route:
 *  - With `?token=…` (clicked from the confirmation email) it confirms the
 *    address, refreshing the session so the EmailVerificationGate releases.
 *  - Without a token it's the "check your inbox" pending gate the signed-in
 *    but unverified account is pinned to, with a resend button.
 */
const VerifyEmail: NextPage = () => {
  const router = useRouter();
  const { user, isAuthenticated, isLoading, logout, refreshUser } = useAuth();

  const rawToken = router.query.token;
  const token =
    typeof rawToken === "string"
      ? rawToken
      : Array.isArray(rawToken)
        ? rawToken[0]
        : "";

  const [confirmStatus, setConfirmStatus] = useState<ConfirmStatus>("verifying");
  const [confirmError, setConfirmError] = useState("");
  const [resendStatus, setResendStatus] = useState<ResendStatus>("idle");
  const [resendError, setResendError] = useState("");
  const confirmedRef = useRef(false);

  // Confirm the token exactly once, as soon as the router hydrates the query.
  useEffect(() => {
    if (!router.isReady || token === "" || confirmedRef.current) return;
    confirmedRef.current = true;

    void (async () => {
      try {
        const res = await fetchWithTimeout(`${ENTRYPOINT}/verify-email`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          credentials: "include",
          body: JSON.stringify({ token }),
        });
        if (res.ok) {
          setConfirmStatus("success");
          // Refresh /api/me so the gate releases if this is the signed-in
          // account. Harmless (401-safe) when confirming from another device.
          await refreshUser();
        } else {
          const data = await res.json().catch(() => ({}));
          setConfirmStatus("error");
          setConfirmError(data.error || "This verification link is invalid.");
        }
      } catch {
        setConfirmStatus("error");
        setConfirmError("Something went wrong. Please try again.");
      }
    })();
  }, [router.isReady, token, refreshUser]);

  const handleResend = useCallback(async () => {
    setResendStatus("sending");
    setResendError("");
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/me/verification/resend`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
      });
      if (res.ok) {
        setResendStatus("sent");
      } else {
        const data = await res.json().catch(() => ({}));
        setResendStatus("error");
        setResendError(data.error || "Couldn't resend the email. Please try again later.");
      }
    } catch {
      setResendStatus("error");
      setResendError("Something went wrong. Please try again.");
    }
  }, []);

  const handleSignOut = useCallback(() => {
    logout();
    void router.replace("/signin");
  }, [logout, router]);

  const inTokenMode = token !== "";

  return (
    <>
      <Head>
        <title>{pageTitle("Confirm your email")}</title>
      </Head>
      <div className="min-h-screen flex flex-col items-center justify-center bg-background px-4 py-10 gap-8">
        <MadoriWordmark />
        <Card className="w-full max-w-lg overflow-hidden">
          {inTokenMode
            ? renderTokenMode()
            : renderPendingMode()}
        </Card>
      </div>
    </>
  );

  function renderTokenMode() {
    if (confirmStatus === "verifying") {
      return (
        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
          <p className="text-muted-foreground">Confirming your email…</p>
        </CardContent>
      );
    }

    if (confirmStatus === "success") {
      return (
        <>
          <CardHeader className="items-center text-center">
            <span className="mb-3 inline-flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600">
              <CheckCircle2 className="h-6 w-6" />
            </span>
            <CardTitle className="text-2xl font-bold">Email confirmed</CardTitle>
            <CardDescription className="text-base">
              Your email address is verified. Your account is ready to go.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4 text-center">
            {isAuthenticated ? (
              <Button className="w-full" onClick={() => void router.replace("/tasks")}>
                Continue to Madori
              </Button>
            ) : (
              <Button asChild className="w-full">
                <Link href="/signin">Sign in</Link>
              </Button>
            )}
          </CardContent>
        </>
      );
    }

    // error
    return (
      <>
        <CardHeader className="items-center text-center">
          <span className="mb-3 inline-flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
            <XCircle className="h-6 w-6" />
          </span>
          <CardTitle className="text-2xl font-bold">Link can&apos;t be used</CardTitle>
          <CardDescription className="text-base">{confirmError}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4 text-center">
          {isAuthenticated ? (
            <>
              <p className="text-sm text-muted-foreground">
                Request a fresh confirmation link below.
              </p>
              {renderResendButton()}
            </>
          ) : (
            <Button asChild variant="outline" className="w-full">
              <Link href="/signin">Sign in to request a new link</Link>
            </Button>
          )}
        </CardContent>
      </>
    );
  }

  function renderPendingMode() {
    if (!isLoading && !isAuthenticated) {
      return (
        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
          <p className="text-muted-foreground">
            Open the confirmation link we emailed you, or sign in to resend it.
          </p>
          <Button asChild className="w-full">
            <Link href="/signin">Sign in</Link>
          </Button>
        </CardContent>
      );
    }

    return (
      <>
        <CardHeader className="items-center text-center">
          <span className="mb-3 inline-flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
            <MailCheck className="h-6 w-6" />
          </span>
          <CardTitle className="text-2xl font-bold">Confirm your email</CardTitle>
          <CardDescription className="text-base">
            One more step before you can start using Madori.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6 text-center">
          <p className="text-sm text-muted-foreground">
            We sent a confirmation link to{" "}
            <span className="font-medium text-foreground">{user?.email}</span>.
            Click it to activate your account. Check your spam folder if it
            hasn&apos;t arrived.
          </p>
          {renderResendButton()}
          <Button variant="ghost" onClick={handleSignOut} className="w-full">
            Sign out
          </Button>
        </CardContent>
      </>
    );
  }

  function renderResendButton() {
    if (resendStatus === "sent") {
      return (
        <p className="text-sm font-medium text-emerald-600">
          A new confirmation email is on its way.
        </p>
      );
    }

    return (
      <div className="space-y-2">
        <Button
          variant="outline"
          className="w-full"
          onClick={() => void handleResend()}
          disabled={resendStatus === "sending"}
        >
          {resendStatus === "sending" ? "Sending…" : "Resend confirmation email"}
        </Button>
        {resendStatus === "error" ? (
          <p className="text-sm text-destructive">{resendError}</p>
        ) : null}
      </div>
    );
  }
};

export default VerifyEmail;
