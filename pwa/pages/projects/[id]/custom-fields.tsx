import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { ChevronRight } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";
import type { Space } from "@/contexts/ActiveSpaceContext";

const resolveProjectSpace = (
  project: { space: string | { "@id": string } },
  spaces: Space[],
): Space | undefined => {
  const iri =
    typeof project.space === "string" ? project.space : project.space["@id"];
  return spaces.find((s) => s["@id"] === iri);
};

const isSpaceAdminFor = (
  space: Space | undefined,
  userId: string,
): boolean =>
  !!space?.userMemberships.some(
    (m) => m.user.id === userId && m.role === "admin",
  );
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
  // Parent-space IRI; resolves against the user's active-space list
  // for the admin role check below.
  space: string | { "@id": string };
}

const CustomFieldsPage = () => {
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
          {project ? `Custom fields - ${project.title}` : "Custom fields"} - Aura
        </title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-5xl mx-auto px-4 py-8 space-y-6">
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
            (() => {
              const space = resolveProjectSpace(project, spaces);
              const isSpaceAdmin = isSpaceAdminFor(space, user.id);
              return (
                <>
                  <nav
                    className="flex items-center gap-1 text-sm text-muted-foreground"
                    aria-label="Breadcrumb"
                  >
                    {space && <span>{space.name}</span>}
                    {space && <ChevronRight className="h-3.5 w-3.5" />}
                    <Link href="/projects" className="hover:text-foreground">
                      Projects
                    </Link>
                    <ChevronRight className="h-3.5 w-3.5" />
                    <Link
                      href={`/projects/${project.id}`}
                      className="hover:text-foreground"
                      data-testid="custom-fields-back-link"
                    >
                      {project.title}
                    </Link>
                    <ChevronRight className="h-3.5 w-3.5" />
                    <span className="text-foreground">Custom fields</span>
                  </nav>

                  <div className="space-y-1">
                    <h1 className="text-2xl font-bold">Custom fields</h1>
                    <p className="text-sm text-muted-foreground">
                      Schema for every task in this project. Each field has a
                      kind, subtype, footer aggregation, and a required flag.
                      Only space admins can edit definitions.
                    </p>
                  </div>

                  <Alert>
                    <AlertDescription>
                      {isSpaceAdmin
                        ? `You're editing as a space admin. Members in ${space?.name ?? "this space"} can use these fields on tasks but can't change definitions.`
                        : `Only space admins can edit definitions. You can use these fields on tasks in ${space?.name ?? "this space"}.`}
                    </AlertDescription>
                  </Alert>

                  {error && (
                    <Alert variant="destructive">
                      <AlertDescription>{error}</AlertDescription>
                    </Alert>
                  )}

                  <CustomFieldsManager
                    projectIri={project["@id"]}
                    spaceName={space?.name}
                    isSpaceAdmin={isSpaceAdmin}
                  />
                </>
              );
            })()
          )}
        </div>
      </main>
    </>
  );
};

export default CustomFieldsPage;
