import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import DiscussionsPanel from "@/components/discussions/DiscussionsPanel";
import type { Space } from "@/contexts/ActiveSpaceContext";

const projectSpaceIri = (project: { space: string | { "@id": string } }): string =>
  typeof project.space === "string" ? project.space : project.space["@id"];

/**
 * Looks up the project's parent space in the user's already-loaded
 * space list and returns true when the caller holds the admin role
 * directly. Falls back to false when the project's space isn't in
 * the list (a stale/cached view, an in-flight refresh) — better to
 * hide admin controls than to show them speculatively.
 */
const isSpaceAdmin = (
  project: { space: string | { "@id": string } },
  userId: string,
  spaces: Space[],
): boolean => {
  const iri = projectSpaceIri(project);
  const space = spaces.find((s) => s["@id"] === iri);
  return !!space?.userMemberships.some(
    (m) => m.user.id === userId && m.role === "admin",
  );
};
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";

interface ProjectMember {
  "@id": string;
  id: string;
  email: string;
}

interface Project {
  "@id": string;
  id: string;
  title: string;
  owner: ProjectMember;
  // Project's serialization gives us the IRI of the parent space; the
  // role lookup happens against the user's already-loaded space list
  // rather than another round-trip.
  space: string | { "@id": string };
}

const DiscussionsListPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { spaces } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const projectId = typeof id === "string" ? id : null;

  const [project, setProject] = useState<Project | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    if (!projectId) return;
    setError(null);
    setIsLoading(true);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/projects/${encodeURIComponent(projectId)}`,
        {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        },
      );
      if (res.status === 404 || res.status === 403) {
        setNotFound(true);
        return;
      }
      if (!res.ok) throw new Error("Failed to load project.");
      const data: Project = await res.json();
      setProject(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load.");
    } finally {
      setIsLoading(false);
    }
  }, [projectId]);

  useEffect(() => {
    if (isAuthenticated && projectId) void load();
  }, [isAuthenticated, projectId, load]);

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
        <title>
          {project ? `Discussions - ${project.title}` : "Discussions"} - Aura
        </title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-4xl mx-auto px-4 py-8 space-y-6">
          {notFound ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">
                  Project not found, or you don&apos;t have access.
                </p>
                <Button asChild variant="link" className="px-0">
                  <Link href="/projects">Back to projects</Link>
                </Button>
              </CardContent>
            </Card>
          ) : isLoading || !project ? (
            <p className="text-muted-foreground">Loading discussions…</p>
          ) : (
            <>
              <div className="space-y-2">
                <Button asChild variant="link" size="sm" className="px-0 h-auto">
                  <Link
                    href={`/projects/${project.id}`}
                    data-testid="discussions-back-link"
                  >
                    <ArrowLeft className="h-3.5 w-3.5 mr-1" />
                    {project.title}
                  </Link>
                </Button>
                <h1 className="text-2xl font-bold">Discussions</h1>
              </div>

              {error && (
                <Alert variant="destructive">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <DiscussionsPanel
                projectId={project.id}
                projectIri={project["@id"]}
                currentUserIri={`/users/${user.id}`}
                isSpaceAdmin={isSpaceAdmin(project, user.id, spaces)}
              />
            </>
          )}
        </div>
      </main>
    </>
  );
};

export default DiscussionsListPage;
