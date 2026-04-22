import Link from "next/link";
import { useAuth } from "../../contexts/AuthContext";

const Navbar = () => {
  const { user, isAuthenticated, logout } = useAuth();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");

  return (
    <nav className="bg-cyan-700 text-white">
      <div className="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
        <Link href="/" className="text-white font-bold text-lg no-underline">
          Aura
        </Link>

        <div className="flex items-center gap-4">
          {isAuthenticated ? (
            <>
              {isAdmin && (
                <Link href="/admin" className="text-cyan-200 hover:text-white no-underline text-sm">
                  Admin
                </Link>
              )}
              <Link href="/tasks" className="text-cyan-200 hover:text-white no-underline text-sm">
                Tasks
              </Link>
              <Link href="/tags" className="text-cyan-200 hover:text-white no-underline text-sm">
                Tags
              </Link>
              <Link href="/account" className="text-cyan-200 hover:text-white no-underline text-sm">
                My Account
              </Link>
              <button
                onClick={logout}
                className="text-cyan-200 hover:text-white text-sm bg-transparent border-0 cursor-pointer"
              >
                Sign Out
              </button>
            </>
          ) : (
            <>
              <Link href="/signin" className="text-cyan-200 hover:text-white no-underline text-sm">
                Sign In
              </Link>
              <Link
                href="/signup"
                className="bg-white text-cyan-700 px-3 py-1 rounded text-sm font-semibold no-underline hover:bg-cyan-100"
              >
                Sign Up
              </Link>
            </>
          )}
        </div>
      </div>
    </nav>
  );
};

export default Navbar;
