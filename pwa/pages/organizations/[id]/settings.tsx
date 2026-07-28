import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Building2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { apiGet, ApiError } from "@/lib/apiClient";
import { type Organization } from "@/lib/organizationTypes";
import { Button } from "@/components/ui/button";
import AccountBillingCard from "@/components/billing/AccountBillingCard";
import { pageTitle } from "@/lib/pageTitle";

const OrganizationSettings = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const orgId = typeof router.query.id === "string" ? router.query.id : null;

  const [org, setOrg] = useState<Organization | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);

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

        {loading && !org ? (
          <p className="text-muted-foreground">Loading…</p>
        ) : (
          <AccountBillingCard
            endpointBase={`/organizations/${orgId}/billing`}
            upgradeLabel="Business"
            upgradeBlurb="Unlimited members, automations, reporting, SSO, and AI assist for everyone in the organization."
            enterpriseNote
          />
        )}
      </div>
    </>
  );
};

export default OrganizationSettings;
