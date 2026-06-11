import type { NextPage } from "next";
import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { Clock } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import AuraWordmark from "@/components/auth/AuraWordmark";
import {
  DeleteAccountDialog,
  ExportDataDialog,
} from "@/components/settings/AccountLifecycleDialogs";
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
 * until an admin opens signups and promotes them. Because the Settings shell
 * is unreachable while waitlisted, the GDPR self-service actions (export +
 * delete) get a small affordance here.
 */
const Waitlist: NextPage = () => {
  const { user, isAuthenticated, isLoading, logout } = useAuth();
  const router = useRouter();
  const [exportOpen, setExportOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  const twoFactorEnabled = Boolean(user?.twoFactor.enabled);

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

  const handleDeleted = () => {
    logout();
    void router.replace("/signin");
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
            <p className="text-xs text-muted-foreground">
              Changed your mind?{" "}
              <button
                type="button"
                className="underline underline-offset-2 hover:text-foreground"
                onClick={() => setExportOpen(true)}
                data-testid="waitlist-export-open"
              >
                Download my data
              </button>{" "}
              or{" "}
              <button
                type="button"
                className="underline underline-offset-2 hover:text-destructive"
                onClick={() => setDeleteOpen(true)}
                data-testid="waitlist-delete-open"
              >
                delete my account
              </button>
              .
            </p>
          </CardContent>
        </Card>
      </div>

      <ExportDataDialog
        open={exportOpen}
        onOpenChange={setExportOpen}
        twoFactorEnabled={twoFactorEnabled}
      />
      <DeleteAccountDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        twoFactorEnabled={twoFactorEnabled}
        email={user?.email ?? ""}
        onDeleted={handleDeleted}
      />
    </>
  );
};

export default Waitlist;
