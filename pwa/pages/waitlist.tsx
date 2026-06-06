import type { NextPage } from "next";
import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect } from "react";
import { Clock } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import AuraWordmark from "@/components/auth/AuraWordmark";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

/**
 * Landing page for waitlisted accounts. The WaitlistGate (mounted in Layout)
 * routes every waitlisted user here; this page is the one screen they can see
 * until an admin opens signups and promotes them.
 */
const Waitlist: NextPage = () => {
  const { user, isAuthenticated, isLoading, logout } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (isLoading) return;
    if (!isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
      return;
    }
    // Promoted (or never waitlisted) → drop them into the app.
    if (user && !user.waitlisted) {
      router.replace("/tasks");
    }
  }, [isLoading, isAuthenticated, user, router]);

  const handleSignOut = () => {
    logout();
    router.replace("/signin");
  };

  if (isLoading || !isAuthenticated || (user && !user.waitlisted)) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>You&apos;re on the waitlist - Aura</title>
      </Head>
      <div className="min-h-screen flex flex-col items-center justify-center bg-background px-4 py-10 gap-8">
        <AuraWordmark />
        <Card className="w-full max-w-lg overflow-hidden">
          <CardHeader className="items-center text-center">
            <span className="mb-3 inline-flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
              <Clock className="h-6 w-6" />
            </span>
            <CardTitle className="text-2xl font-bold">You&apos;re on the waitlist</CardTitle>
            <CardDescription className="text-base">
              Aura isn&apos;t open to everyone just yet. We&apos;re onboarding
              people in batches.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6 text-center">
            <p className="text-sm text-muted-foreground">
              We&apos;ll email{" "}
              <span className="font-medium text-foreground">{user?.email}</span>{" "}
              the moment your account is ready. You don&apos;t need to do
              anything — just keep an eye on your inbox.
            </p>
            <Button variant="outline" onClick={handleSignOut} className="w-full">
              Sign out
            </Button>
          </CardContent>
        </Card>
      </div>
    </>
  );
};

export default Waitlist;
