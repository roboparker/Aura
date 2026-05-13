import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import DiscussionsPanel from "@/components/discussions/DiscussionsPanel";
import { Card, CardContent } from "@/components/ui/card";

const AllDiscussionsPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, isActiveSpaceAdmin } = useActiveSpace();
  const router = useRouter();

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

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
        <div className="max-w-4xl mx-auto px-4 py-8 space-y-6">
          <header>
            <h1 className="text-2xl font-bold">Discussions</h1>
            <p className="text-sm text-muted-foreground mt-1">
              {activeSpace
                ? `Threads in "${activeSpace.name}".`
                : "Pick a space to see its threads."}
            </p>
          </header>

          {activeSpace ? (
            <DiscussionsPanel
              spaceIri={activeSpace["@id"]}
              currentUserIri={`/users/${user.id}`}
              isSpaceAdmin={isActiveSpaceAdmin}
            />
          ) : (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground text-sm">
                  No space selected.
                </p>
              </CardContent>
            </Card>
          )}
        </div>
      </main>
    </>
  );
};

export default AllDiscussionsPage;
