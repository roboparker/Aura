import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Building2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { apiGet, ApiError } from "@/lib/apiClient";
import { ENTRYPOINT } from "@/config/entrypoint";
import { type Organization } from "@/lib/organizationTypes";
import { Button } from "@/components/ui/button";
import AccountBillingCard from "@/components/billing/AccountBillingCard";
import DeleteOrganizationDialog from "@/components/organizations/DeleteOrganizationDialog";
import ScheduledDeletionBanner from "@/components/deletion/ScheduledDeletionBanner";
import { pageTitle } from "@/lib/pageTitle";

const OrganizationSettings = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const orgId = typeof router.query.id === "string" ? router.query.id : null;

  const [org, setOrg] = useState<Organization | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  const twoFactorEnabled = Boolean(user?.twoFactor?.enabled);
  // Deleting or restoring the account is the owner's call — admins manage
  // members and settings, which is a different thing from ending the account.
  const isOwner =
    org?.memberList?.some(
      (m) => m.role === "owner" && m.user.id === user?.id,
    ) ?? false;

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    if (!orgId) return;
    setLoading(true);
    setNotFound(false);
    try {
      setOrg(await apiGet<Organization>(`/organizations/${orgId}`));
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) setNotFound(true);
      setOrg(null);
    } finally {
      setLoading(false);
    }
  }, [orgId]);

  useEffect(() => {
    if (isAuthenticated && orgId) void load();
  }, [isAuthenticated, orgId, load]);

  const restore = useCallback(
    async (credential: string) => {
      const res = await fetch(`${ENTRYPOINT}/organizations/${orgId}/restore`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(
          twoFactorEnabled
            ? { totpCode: credential.trim() }
            : { currentPassword: credential },
        ),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Could not restore this organization.");
      }
      await load();
    },
    [orgId, twoFactorEnabled, load],
  );

  if (authLoading || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (notFound) {
    return (
      <div className="px-6 py-16 max-w-2xl mx-auto text-center">
        <h1 className="text-lg font-semibold">Organization not found</h1>
        <Button asChild variant="outline" className="mt-4">
          <Link href="/organizations">Back to organizations</Link>
        </Button>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>{pageTitle(org ? `${org.name} settings` : "Organization settings")}</title>
      </Head>

      <div className="px-6 py-8 max-w-3xl mx-auto">
        <Link
          href={orgId ? `/organizations/${orgId}` : "/organizations"}
          className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-3.5 w-3.5" />
          {org?.name ?? "Organization"}
        </Link>

        <div className="mb-6 flex items-center gap-3">
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-cyan-600/10 text-cyan-700">
            <Building2 className="h-5 w-5" aria-hidden />
          </div>
          <div>
            <h1 className="text-xl font-semibold">Organization settings</h1>
            <p className="text-sm text-muted-foreground">{org?.name}</p>
          </div>
        </div>

        {org?.deletedAt && (
          <div className="mb-6">
            <ScheduledDeletionBanner
              targetType="organization"
              name={org.name}
              purgeAfter={org.purgeAfter}
              twoFactorEnabled={twoFactorEnabled}
              onRestore={restore}
            />
          </div>
        )}

        {loading && !org ? (
          <p className="text-muted-foreground">Loading…</p>
        ) : (
          <>
            <AccountBillingCard
              endpointBase={`/organizations/${orgId}/billing`}
              upgradeLabel="Business"
              upgradeBlurb="Unlimited members, automations, reporting, SSO, and AI assist for everyone in the organization."
              enterpriseNote
            />

            {org && isOwner && !org.deletedAt && (
              <section className="mt-8 rounded-lg border border-destructive/40 p-4">
                <h2 className="text-sm font-semibold text-destructive">
                  Danger zone
                </h2>
                <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-sm font-medium">Delete this organization</p>
                    <p className="text-sm text-muted-foreground">
                      Every space it owns goes with it. You&apos;ll have 30 days
                      to undo, and we&apos;ll email every owner a restore link.
                    </p>
                  </div>
                  <Button
                    variant="destructive"
                    onClick={() => setDeleteOpen(true)}
                  >
                    Delete organization
                  </Button>
                </div>
              </section>
            )}
          </>
        )}
      </div>

      {org && (
        <DeleteOrganizationDialog
          open={deleteOpen}
          onOpenChange={setDeleteOpen}
          organization={org}
          twoFactorEnabled={twoFactorEnabled}
          onScheduled={() => {
            setDeleteOpen(false);
            void load();
          }}
        />
      )}
    </>
  );
};

export default OrganizationSettings;
