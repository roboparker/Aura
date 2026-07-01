import { ReactNode, useState } from "react";
import { useRouter } from "next/router";
import {
  DehydratedState,
  HydrationBoundary,
  QueryClient,
  QueryClientProvider,
} from "@tanstack/react-query";
import { AuthProvider, useAuth } from "@/contexts/AuthContext";
import { ActiveSpaceProvider } from "@/contexts/ActiveSpaceContext";
import TwoFactorRecoveryInterstitial from "@/components/auth/TwoFactorRecoveryInterstitial";
import WaitlistGate from "@/components/auth/WaitlistGate";
import Footer from "./Footer";
import ImpersonationBanner from "./ImpersonationBanner";
import Navbar from "./Navbar";
import Sidebar from "./Sidebar";

/**
 * Standalone auth/entry screens that must NEVER render inside the app chrome
 * (sidebar/navbar) — even if a stale `user` lingers from an expired session
 * mid-redirect. Gating on the route (not just auth state) is what stops the
 * sign-in screen from appearing behind the logged-in sidebar.
 */
const CHROME_FREE_PATHS = new Set([
  "/signin",
  "/signup",
  "/forgot-password",
  "/reset-password",
  "/waitlist",
]);

/**
 * Inner shell rendered beneath the auth/space providers. A waitlisted account
 * only ever sees the /waitlist gate, so we render its page chrome-free (no
 * sidebar/navbar) — it reads as a standalone screen rather than the empty app.
 */
const AppShell = ({ children }: { children: ReactNode }) => {
  const { user } = useAuth();
  const router = useRouter();

  if (user?.waitlisted || CHROME_FREE_PATHS.has(router.pathname)) {
    return <>{children}</>;
  }

  return (
    // Full-width sticky header (ImpersonationBanner above the top bar,
    // pinned together) spanning the whole viewport; below it a row of
    // persistent left sidebar (`md:` and up) + content. The navbar's
    // mobile Sheet handles the small-screen sidebar case. The Sidebar
    // renders nothing for unauthenticated visitors so the marketing/auth
    // screens keep their original full-width layout.
    // Full-height left sidebar (`md:` and up) pinned to the viewport edge;
    // the header (ImpersonationBanner + Navbar) and page content live in the
    // column to its right. The navbar's mobile Sheet handles the small-screen
    // sidebar case. The Sidebar renders nothing for unauthenticated visitors
    // so the marketing/auth screens keep their original full-width layout.
    <div className="flex min-h-screen">
      <Sidebar />
      <div className="flex flex-1 min-w-0 flex-col">
        {/* Above the page's sticky layers (project title/tabs bar = z-30,
            column header = z-20) so a short page scrolling up can't push them
            over the navbar. Stays below portalled popovers/menus (z-50). */}
        <div className="sticky top-0 z-40">
          <ImpersonationBanner />
          <Navbar />
        </div>
        <div className="flex flex-1 min-h-0 flex-col">
          <div className="flex-1">{children}</div>
          <Footer />
        </div>
      </div>
    </div>
  );
};

const Layout = ({
  children,
  dehydratedState,
}: {
  children: ReactNode;
  dehydratedState: DehydratedState;
}) => {
  const [queryClient] = useState(() => new QueryClient());

  return (
    <QueryClientProvider client={queryClient}>
      <HydrationBoundary state={dehydratedState}>
        <AuthProvider>
          <ActiveSpaceProvider>
            <AppShell>{children}</AppShell>
            {/* Forced modal that appears the moment the API signals
                this session signed in with a backup code. See
                TwoFactorRecoveryInterstitial for the flow. Renders
                nothing when not pending so it costs no DOM. */}
            <TwoFactorRecoveryInterstitial />
            {/* Keeps a waitlisted account pinned to the /waitlist gate
                wherever it tries to navigate. Renders nothing for normal
                users. */}
            <WaitlistGate />
          </ActiveSpaceProvider>
        </AuthProvider>
      </HydrationBoundary>
    </QueryClientProvider>
  );
};

export default Layout;
