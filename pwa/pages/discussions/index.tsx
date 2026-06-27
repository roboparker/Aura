import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect } from "react";
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
        <title>Discussions - Madori</title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-5xl mx-auto px-4 py-8">
          {activeSpace ? (
            <DiscussionsPanel
              spaceIri={activeSpace["@id"]}
              currentUserIri={`/users/${user.id}`}
              isSpaceAdmin={isActiveSpaceAdmin}
              title="Discussions"
              description="Space-level threads — announcements, ideas, Q&A, and anything that doesn’t belong inside a project."
              icon={
                <MessagesSquare className="h-6 w-6 text-cyan-600 dark:text-cyan-400" />
              }
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
