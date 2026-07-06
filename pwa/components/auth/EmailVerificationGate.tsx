import { useEffect } from "react";
import { useRouter } from "next/router";
import { useAuth } from "@/contexts/AuthContext";

/**
 * App-shell guard for accounts that haven't confirmed their email. Mirrors
 * WaitlistGate: an unverified user can authenticate but holds no ROLE_USER
 * server-side, so every real resource 403s. Bounce them to /verify-email
 * (which doubles as the "confirm your email" pending screen) wherever else
 * they land. Purely UX — the server is the real boundary.
 *
 * Waitlisting takes precedence: a waitlisted account is handled by
 * WaitlistGate, so we skip it here to avoid two guards fighting.
 */
const EmailVerificationGate = () => {
  const { user } = useAuth();
  const router = useRouter();
  const needsVerification =
    !!user && user.emailVerified === false && !user.waitlisted;

  useEffect(() => {
    if (needsVerification && router.pathname !== "/verify-email") {
      router.replace("/verify-email");
    }
  }, [needsVerification, router]);

  return null;
};

export default EmailVerificationGate;
