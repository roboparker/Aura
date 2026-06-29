import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect } from "react";
import { ListChecks } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";
import PageHeader from "@/components/common/PageHeader";
import { Card, CardContent } from "@/components/ui/card";

/**
 * Space-level custom-field manager (#custom-fields-space). Defines the field
 * schema shared across the active space's projects. Linked from the sidebar
 * Settings section. Project-specific bits (drag-reorder + the FILLED stat) live
 * on each project's Settings → Custom fields tab, which mounts the same manager
 * with a project context.
 */
const CustomFields = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  if (authLoading || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading...</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Custom fields - Madori</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-8">
        <div className="mx-auto w-full max-w-4xl">
          <PageHeader
            title="Custom fields"
            icon={<ListChecks className="h-6 w-6 text-cyan-600 dark:text-cyan-400" />}
            subtitle={
              <>
                Reusable task fields defined once for
                {activeSpace ? ` the “${activeSpace.name}” space` : " this space"}{" "}
                — each project picks which to show on its tasks.
              </>
            }
          />

          {activeSpace ? (
            <CustomFieldsManager
              spaceIri={activeSpace["@id"]}
              projectTitle={activeSpace.name}
              spaceName={activeSpace.name}
              isSpaceAdmin={can("custom_fields", "update")}
            />
          ) : (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">
                  Select a space to manage its custom fields.
                </p>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </>
  );
};

export default CustomFields;
