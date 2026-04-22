import { ReactNode, useState } from "react";
import {
  DehydratedState,
  HydrationBoundary,
  QueryClient,
  QueryClientProvider,
} from "@tanstack/react-query";
import { AuthProvider } from "../../contexts/AuthContext";
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
    <QueryClientProvider client={queryClient}>
      <HydrationBoundary state={dehydratedState}>
        <AuthProvider>
          <Navbar />
          {children}
        </AuthProvider>
      </HydrationBoundary>
    </QueryClientProvider>
  );
};

export default Layout;
