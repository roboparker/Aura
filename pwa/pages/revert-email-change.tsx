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
  | { status: "success"; restoredEmail: string }
  | { status: "error"; message: string };

const RevertEmailChange = () => {
  const router = useRouter();
  const { refreshUser } = useAuth();
  const token = typeof router.query.token === "string" ? router.query.token : "";
  const [state, setState] = useState<State>({ status: "idle" });

  useEffect(() => {
    if (!router.isReady) return;
    if (!token) {
      setState({ status: "error", message: "Missing revert token." });
      return;
    }
    if (state.status !== "idle") return;

    let cancelled = false;
    setState({ status: "loading" });
    (async () => {
      try {
        const res = await fetch(`${ENTRYPOINT}/auth/revert-email-change`, {
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
            message: data.error || "Invalid or expired revert link.",
          });
          return;
        }
        await refreshUser();
        setState({ status: "success", restoredEmail: data.restoredEmail ?? "" });
      } catch {
        if (!cancelled) {
          setState({
            status: "error",
            message: "Something went wrong reverting your email.",
          });
        }
      }
    })();

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [router.isReady, token]);

  return (
    <>
      <Head>
        <title>Undo Email Change - Aura</title>
      </Head>
      <main className="min-h-screen flex items-center justify-center bg-muted px-4 py-12">
        <Card className="w-full max-w-md">
          <CardHeader>
            <CardTitle className="text-2xl text-center">Undo Email Change</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {state.status === "loading" || state.status === "idle" ? (
              <p className="text-muted-foreground text-center">Reverting…</p>
            ) : state.status === "success" ? (
              <>
                <Alert>
                  <AlertDescription>
                    Your email has been restored
                    {state.restoredEmail ? (
                      <>
                        {" "}
                        to <strong>{state.restoredEmail}</strong>
                      </>
                    ) : null}
                    . If this change wasn&rsquo;t made by you, we strongly
                    recommend resetting your password as well.
                  </AlertDescription>
                </Alert>
                <Button asChild className="w-full">
                  <Link href="/forgot-password">Reset my password</Link>
                </Button>
                <Button asChild variant="outline" className="w-full">
                  <Link href="/signin">Back to sign in</Link>
                </Button>
              </>
            ) : (
              <>
                <Alert variant="destructive">
                  <AlertDescription>{state.message}</AlertDescription>
                </Alert>
                <Button asChild variant="outline" className="w-full">
                  <Link href="/signin">Back to sign in</Link>
                </Button>
              </>
            )}
          </CardContent>
        </Card>
      </main>
    </>
  );
};

export default RevertEmailChange;
