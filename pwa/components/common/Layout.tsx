import { ReactNode, useState } from "react";
import {
  DehydratedState,
  HydrationBoundary,
  QueryClient,
  QueryClientProvider,
} from "@tanstack/react-query";
import { ThemeProvider } from "next-themes";
import { AuthProvider } from "@/contexts/AuthContext";
import Navbar from "./Navbar";

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
            <Navbar />
            {children}
          </AuthProvider>
        </HydrationBoundary>
      </QueryClientProvider>
    </ThemeProvider>
  );
};

export default Layout;
