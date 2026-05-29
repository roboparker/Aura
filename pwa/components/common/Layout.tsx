import { ReactNode, useState } from "react";
import {
  DehydratedState,
  HydrationBoundary,
  QueryClient,
  QueryClientProvider,
} from "@tanstack/react-query";
import { ThemeProvider } from "next-themes";
import { AuthProvider } from "@/contexts/AuthContext";
import { ActiveSpaceProvider } from "@/contexts/ActiveSpaceContext";
import TwoFactorRecoveryInterstitial from "@/components/auth/TwoFactorRecoveryInterstitial";
import Footer from "./Footer";
import Navbar from "./Navbar";
import Sidebar from "./Sidebar";

const Layout = ({
  children,
  dehydratedState,
}: {
  children: ReactNode;
  dehydratedState: DehydratedState;
}) => {
  const [queryClient] = useState(() => new QueryClient());

  return (
    // No explicit user choice → follow the OS `prefers-color-scheme`.
    // Once the user clicks the toggle we persist their choice in localStorage,
    // and that takes precedence over the system pref.
    <ThemeProvider
      attribute="class"
      defaultTheme="system"
      enableSystem
      disableTransitionOnChange
    >
      <QueryClientProvider client={queryClient}>
        <HydrationBoundary state={dehydratedState}>
          <AuthProvider>
            <ActiveSpaceProvider>
              <div className="flex min-h-screen flex-col">
                <Navbar />
                {/* Persistent left sidebar (`md:` and up) when signed
                    in; the navbar's mobile Sheet handles the small-
                    screen case. Sidebar renders nothing for
                    unauthenticated visitors so the marketing/auth
                    screens keep their original full-width layout. */}
                <div className="flex flex-1">
                  <Sidebar />
                  <div className="flex-1 min-w-0">{children}</div>
                </div>
                <Footer />
              </div>
              {/* Forced modal that appears the moment the API signals
                  this session signed in with a backup code. See
                  TwoFactorRecoveryInterstitial for the flow. Renders
                  nothing when not pending so it costs no DOM. */}
              <TwoFactorRecoveryInterstitial />
            </ActiveSpaceProvider>
          </AuthProvider>
        </HydrationBoundary>
      </QueryClientProvider>
    </ThemeProvider>
  );
};

export default Layout;
