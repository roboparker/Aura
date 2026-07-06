import type { NextPage } from "next";
import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect } from "react";
import { Globe } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import CustomFieldsManager from "@/components/custom-fields/CustomFieldsManager";
import PageHeader from "@/components/common/PageHeader";

/**
 * Admin-only manager for instance-wide GLOBAL custom fields
 * (#global-custom-fields). Reuses the space {@link CustomFieldsManager} UX
 * (kind/subtype editors, create/edit/delete drawer) but points it at
 * `/global_custom_field_definitions` and drops the space/board context —
 * global fields belong to no space and any board can opt into them.
 * ROLE_ADMIN gated (matching the segments / waitlist admin pages).
 */
const AdminGlobalCustomFields: NextPage = () => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (!isAuthenticated) return null;

  if (!isAdmin) {
    return (
      <>
        <Head>
          <title>Access Denied - Madori</title>
        </Head>
        <div className="min-h-screen flex items-center justify-center bg-muted px-4">
          <div className="text-center">
            <h1 className="text-2xl font-bold text-foreground mb-2">
              Access Denied
            </h1>
            <p className="text-muted-foreground">
              You need administrator privileges to view this page.
            </p>
          </div>
        </div>
      </>
    );
  }

  return (
    <>
      <Head>
        <title>Global custom fields - Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-8">
        <div className="mx-auto w-full max-w-4xl">
          <PageHeader
            title="Global custom fields"
            icon={<Globe className="h-6 w-6 text-cyan-600 dark:text-cyan-400" />}
            subtitle={
              <>
                Reusable task fields available to every board in every space.
                Admins define them here; board members can only toggle them
                on or off per board.
              </>
            }
          />

          <CustomFieldsManager
            boardTitle="Global"
            collectionPath="/global_custom_field_definitions"
            isSpaceAdmin
          />
        </div>
      </div>
    </>
  );
};

export default AdminGlobalCustomFields;
