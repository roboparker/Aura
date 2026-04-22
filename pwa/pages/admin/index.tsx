import type { NextPage } from "next";
import dynamic from "next/dynamic";
import { useRouter } from "next/router";
import { useEffect } from "react";
import Head from "next/head";
import { useAuth } from "../../contexts/AuthContext";

// load the admin client-side
const App = dynamic(() => import("../../components/admin/App"), {
  ssr: false,
  loading: () => <p>Loading...</p>,
});

const Admin: NextPage = () => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.push("/signin");
    }
  }, [isLoading, isAuthenticated, router]);

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <p className="text-gray-500">Loading...</p>
      </div>
    );
  }

  if (!isAuthenticated) {
    return null;
  }

  if (!isAdmin) {
    return (
      <>
        <Head>
          <title>Access Denied - Aura</title>
        </Head>
        <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
          <div className="text-center">
            <h1 className="text-2xl font-bold text-black mb-2">Access Denied</h1>
            <p className="text-gray-600">You need administrator privileges to view this page.</p>
          </div>
        </div>
      </>
    );
  }

  return <App />;
};

export default Admin;
