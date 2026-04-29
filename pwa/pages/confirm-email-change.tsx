import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { useAuth } from "@/contexts/AuthContext";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

type State =
  | { status: "idle" }
  | { status: "loading" }
  | { status: "success"; newEmail: string }
  | { status: "error"; message: string };

const ConfirmEmailChange = () => {
  const router = useRouter();
  const { refreshUser } = useAuth();
  const token = typeof router.query.token === "string" ? router.query.token : "";
  const [state, setState] = useState<State>({ status: "idle" });

  useEffect(() => {
    if (!router.isReady) return;
    if (!token) {
      setState({ status: "error", message: "Missing confirmation token." });
      return;
    }
    if (state.status !== "idle") return;

    let cancelled = false;
    setState({ status: "loading" });
    (async () => {
      try {
        const res = await fetch(`${ENTRYPOINT}/auth/confirm-email-change`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ token }),
        });
        const data = await res.json().catch(() => ({}));
        if (cancelled) return;
        if (!res.ok) {
          setState({
            status: "error",
            message: data.error || "Invalid or expired confirmation link.",
          });
          return;
        }
        // If the current session belongs to the user whose email just
        // changed, refresh it so the navbar avatar/email update too.
        await refreshUser();
        setState({ status: "success", newEmail: data.newEmail ?? "" });
      } catch {
        if (!cancelled) {
          setState({
            status: "error",
            message: "Something went wrong confirming your email.",
          });
        }
      }
    })();

    return () => {
      cancelled = true;
    };
    // We intentionally only kick off the request once the router is ready
    // and we have a token; the state guard above protects against the
    // strict-mode double-invocation in dev.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [router.isReady, token]);

  return (
    <>
      <Head>
        <title>Confirm Email Change - Aura</title>
      </Head>
      <main className="min-h-screen flex items-center justify-center bg-muted px-4 py-12">
        <Card className="w-full max-w-md">
          <CardHeader>
            <CardTitle className="text-2xl text-center">Confirm Email Change</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {state.status === "loading" || state.status === "idle" ? (
              <p className="text-muted-foreground text-center">Confirming…</p>
            ) : state.status === "success" ? (
              <>
                <Alert>
                  <AlertDescription>
                    Your email has been updated
                    {state.newEmail ? (
                      <>
                        {" "}
                        to <strong>{state.newEmail}</strong>
                      </>
                    ) : null}
                    . We&rsquo;ve also notified your previous address with a
                    link to undo this change in case it wasn&rsquo;t you.
                  </AlertDescription>
                </Alert>
                <Button asChild className="w-full">
                  <Link href="/account">Back to my account</Link>
                </Button>
              </>
            ) : (
              <>
                <Alert variant="destructive">
                  <AlertDescription>{state.message}</AlertDescription>
                </Alert>
                <Button asChild variant="outline" className="w-full">
                  <Link href="/account">Back to my account</Link>
                </Button>
              </>
            )}
          </CardContent>
        </Card>
      </main>
    </>
  );
};

export default ConfirmEmailChange;
