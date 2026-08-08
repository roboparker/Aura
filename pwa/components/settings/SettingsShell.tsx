import { ReactNode, useEffect } from "react";
import Head from "next/head";
import { useRouter } from "next/router";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import SettingsNav, { type SettingsSectionKey } from "./SettingsNav";
import { pageTitle } from "@/lib/pageTitle";

interface SettingsShellProps {
  active: SettingsSectionKey;
  title: string;
  description?: string;
  /** Right-aligned header slot (e.g. a save indicator). */
  actions?: ReactNode;
  children: ReactNode;
}

/**
 * Shared chrome for every `/settings/*` panel: the consolidated subnav, the
 * panel header, and the auth gate (redirect to sign-in when logged out) so
 * each sub-page stays focused on its own content.
 */
const SettingsShell = ({
  active,
  title,
  description,
  actions,
  children,
}: SettingsShellProps) => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  if (isLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>{pageTitle(title, "Settings")}</title>
      </Head>
      <div className="min-h-screen bg-muted">
        <div className="mx-auto max-w-5xl px-4 py-10">
          <div className="mb-6">
            <h1 className="text-2xl font-bold tracking-tight">Settings</h1>
            <p className="text-sm text-muted-foreground">
              Manage your account, security, and preferences.
            </p>
          </div>

          <div className="grid gap-8 md:grid-cols-[12rem_minmax(0,1fr)]">
            <SettingsNav active={active} />

            <div className="min-w-0 space-y-6">
              <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h2 className="text-lg font-semibold">{title}</h2>
                  {description && (
                    <p className="text-sm text-muted-foreground">{description}</p>
                  )}
                </div>
                {actions}
              </header>
              {children}
            </div>
          </div>
        </div>
      </div>
    </>
  );
};

export default SettingsShell;
