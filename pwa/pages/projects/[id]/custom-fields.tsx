import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";
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
}

const CustomFieldsPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
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
          {project ? `Custom fields - ${project.title}` : "Custom fields"} - Aura
        </title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-3xl mx-auto px-4 py-8 space-y-6">
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
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <>
              <div className="space-y-2">
                <Button
                  asChild
                  variant="link"
                  size="sm"
                  className="px-0 h-auto"
                >
                  <Link
                    href={`/projects/${project.id}`}
                    data-testid="custom-fields-back-link"
                  >
                    <ArrowLeft className="h-3.5 w-3.5 mr-1" />
                    {project.title}
                  </Link>
                </Button>
                <h1 className="text-2xl font-bold">Custom fields</h1>
                <p className="text-sm text-muted-foreground">
                  Per-project schema for structured task data. Project members
                  can see the schema; only the owner can change it.
                </p>
              </div>

              {error && (
                <Alert variant="destructive">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <CustomFieldsManager
                projectIri={project["@id"]}
                isProjectOwner={user.email === project.owner.email}
              />
            </>
          )}
        </div>
      </main>
    </>
  );
};

export default CustomFieldsPage;
