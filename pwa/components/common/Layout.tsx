import { ReactNode, useState } from "react";
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
 * Inner shell rendered beneath the auth/space providers. A waitlisted account
 * only ever sees the /waitlist gate, so we render its page chrome-free (no
 * sidebar/navbar) — it reads as a standalone screen rather than the empty app.
 */
const AppShell = ({ children }: { children: ReactNode }) => {
  const { user } = useAuth();

  if (user?.waitlisted) {
    return <>{children}</>;
  }

  return (
    // Full-width sticky header (ImpersonationBanner above the top bar,
    // pinned together) spanning the whole viewport; below it a row of
    // persistent left sidebar (`md:` and up) + content. The navbar's
    // mobile Sheet handles the small-screen sidebar case. The Sidebar
    // renders nothing for unauthenticated visitors so the marketing/auth
    // screens keep their original full-width layout.
    <div className="flex min-h-screen flex-col">
      <div className="sticky top-0 z-30">
        <ImpersonationBanner />
        <Navbar />
      </div>
      <div className="flex flex-1 min-h-0">
        <Sidebar />
        <div className="flex-1 min-w-0 flex flex-col">
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
