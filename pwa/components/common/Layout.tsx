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
              <Navbar />
              {/* Persistent left sidebar (`md:` and up) when signed
                  in; the navbar's mobile Sheet handles the small-
                  screen case. Sidebar renders nothing for
                  unauthenticated visitors so the marketing/auth
                  screens keep their original full-width layout. */}
              <div className="flex">
                <Sidebar />
                <div className="flex-1 min-w-0">{children}</div>
              </div>
            </ActiveSpaceProvider>
          </AuthProvider>
        </HydrationBoundary>
      </QueryClientProvider>
    </ThemeProvider>
  );
};

export default Layout;
