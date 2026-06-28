import { useEffect } from "react";
import { useRouter } from "next/router";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";

/**
 * The standalone groups index was folded into the space Users page
 * (`/spaces/{id}/users`) — groups are managed there alongside members and
 * invites. Redirect any old `/groups` link to the active space's Users page.
 */
const GroupsRedirect = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();

  useEffect(() => {
    if (authLoading) return;
    if (!isAuthenticated) {
      router.replace(signinHrefForCurrent("/groups"));
      return;
    }
    if (activeSpace) {
      router.replace(`/spaces/${activeSpace.id}/users`);
    }
  }, [authLoading, isAuthenticated, activeSpace, router]);

  return (
    <div className="min-h-screen flex items-center justify-center bg-muted">
      <p className="text-muted-foreground">Loading…</p>
    </div>
  );
};

export default GroupsRedirect;
