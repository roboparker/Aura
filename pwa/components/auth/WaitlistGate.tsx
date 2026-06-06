import { useEffect } from "react";
import { useRouter } from "next/router";
import { useAuth } from "@/contexts/AuthContext";

/**
 * App-shell guard for waitlisted accounts. A waitlisted user can authenticate
 * but holds no ROLE_USER server-side, so every real resource 403s. Rather than
 * let them wander into walls of empty/forbidden screens, bounce them to the
 * /waitlist gate whenever they land anywhere else. Purely UX — the server is
 * the real boundary. Mounted once in Layout, alongside the 2FA interstitial.
 */
const WaitlistGate = () => {
  const { user } = useAuth();
  const router = useRouter();
  const waitlisted = user?.waitlisted ?? false;

  useEffect(() => {
    if (waitlisted && router.pathname !== "/waitlist") {
      router.replace("/waitlist");
    }
  }, [waitlisted, router]);

  return null;
};

export default WaitlistGate;
