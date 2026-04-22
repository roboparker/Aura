import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect } from "react";
import { useAuth } from "../contexts/AuthContext";
import ChangePasswordForm from "../components/account/ChangePasswordForm";

const Account = () => {
  const { user, isAuthenticated, isLoading, logout } = useAuth();
  const router = useRouter();

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

  if (!user) {
    return null;
  }

  return (
    <>
      <Head>
        <title>My Account - Aura</title>
      </Head>
      <div className="min-h-screen bg-gray-50 px-4 py-12">
        <div className="max-w-md mx-auto bg-white rounded-lg shadow-card p-8">
          <h1 className="text-2xl font-bold text-black mb-6">My Account</h1>

          <div className="space-y-4">
            <div>
              <p className="text-sm font-medium text-gray-500">Email</p>
              <p className="text-black">{user.email}</p>
            </div>

            <div>
              <p className="text-sm font-medium text-gray-500">Roles</p>
              <div className="flex gap-2 mt-1">
                {user.roles.map((role) => (
                  <span
                    key={role}
                    className="inline-block bg-cyan-100 text-cyan-700 text-xs font-medium px-2 py-1 rounded"
                  >
                    {role}
                  </span>
                ))}
              </div>
            </div>
          </div>

          <ChangePasswordForm />

          <button
            onClick={() => {
              logout();
              router.push("/");
            }}
            className="mt-8 w-full bg-red-500 text-white py-2 px-4 rounded-md font-semibold hover:bg-red-600"
          >
            Sign Out
          </button>
        </div>
      </div>
    </>
  );
};

export default Account;
