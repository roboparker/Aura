import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { MessagesSquare } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import DiscussionsPanel from "@/components/discussions/DiscussionsPanel";
import { Card, CardContent } from "@/components/ui/card";

const AllDiscussionsPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, isActiveSpaceAdmin } = useActiveSpace();
  const router = useRouter();
  const [count, setCount] = useState<number | null>(null);
  const activeSpaceIri = activeSpace?.["@id"];

  // Stable so it doesn't retrigger the panel's load effect.
  const onCountChange = useCallback((n: number) => setCount(n), []);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  // Reset the chip when switching spaces so a stale count never flashes.
  useEffect(() => {
    setCount(null);
  }, [activeSpaceIri]);

  if (authLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Discussions - Aura</title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-5xl mx-auto px-4 py-8 space-y-6">
          <header className="space-y-1">
            <div className="flex items-center gap-2">
              <MessagesSquare className="h-6 w-6 text-cyan-600 dark:text-cyan-400" />
              <h1 className="text-2xl font-bold">Discussions</h1>
              {count !== null && (
                <span
                  className="rounded-full bg-muted-foreground/15 px-2 py-0.5 text-xs font-medium text-muted-foreground"
                  data-testid="discussions-count"
                >
                  {count}
                </span>
              )}
            </div>
            <p className="text-sm text-muted-foreground">
              Space-level threads — announcements, ideas, Q&amp;A, and anything
              that doesn&apos;t belong inside a project.
            </p>
          </header>

          {activeSpace ? (
            <DiscussionsPanel
              spaceIri={activeSpace["@id"]}
              currentUserIri={`/users/${user.id}`}
              isSpaceAdmin={isActiveSpaceAdmin}
              onCountChange={onCountChange}
            />
          ) : (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground text-sm">No space selected.</p>
              </CardContent>
            </Card>
          )}
        </div>
      </main>
    </>
  );
};

export default AllDiscussionsPage;
