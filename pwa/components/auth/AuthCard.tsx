import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/router";
import { useAuth } from "@/contexts/AuthContext";
import { safeNextPath } from "@/lib/authRedirect";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import AuraWordmark from "./AuraWordmark";
import SignInForm from "./SignInForm";
import SignUpForm from "./SignUpForm";
import TwoFactorChallengeForm, {
  type TwoFactorMode,
} from "./TwoFactorChallengeForm";

type Tab = "signin" | "signup";

interface Props {
  /** Which form this page entry point renders. */
  defaultTab: Tab;
}

/**
 * Current step of the sign-in/up flow. The 2FA modes are reached only via
 * the credentials step — they're tracked here (not inside SignInForm) so
 * the footer can swap in lockstep with the form body.
 */
type AuthStep = "credentials" | "signup" | "totp" | "backup";

interface CopyEntry {
  title: string;
  subtitle: string;
  switchLabel: string;
  switchCta: string;
}

const COPY: Record<Tab, { title: string; subtitle: string; switchLabel: string; switchCta: string; switchPath: string }> = {
  signin: {
    title: "Welcome back",
    subtitle: "Sign in to your Aura workspace.",
    switchLabel: "New to Aura?",
    switchCta: "Create an account",
    switchPath: "/signup",
  },
  signup: {
    title: "Create your account",
    subtitle: "Get started with Aura in seconds.",
    switchLabel: "Already have an account?",
    switchCta: "Sign in",
    switchPath: "/signin",
  },
};

/**
 * 2FA copy is built per-render because the subtitle weaves in the
 * caller's email — easier to keep here as one small function than to
 * thread template tokens through the COPY map.
 */
const twoFactorCopy = (mode: TwoFactorMode, email: string | null): CopyEntry => {
  const forEmail = email ? ` for ${email}` : "";
  if (mode === "totp") {
    return {
      title: "Two-factor authentication",
      subtitle: `Enter the 6-digit code from your authenticator app${forEmail}.`,
      switchLabel: "",
      switchCta: "Use a backup code instead",
    };
  }
  return {
    title: "Use a backup code",
    subtitle: "One of the backup codes you saved when you enabled 2FA. Each can be used once.",
    switchLabel: "",
    switchCta: "Back to authenticator code",
  };
};

const AuthCard = ({ defaultTab }: Props) => {
  const router = useRouter();
  const { isAuthenticated, isLoading } = useAuth();
  // The credentials step keeps the form's existing two-tab feel; the
  // 2FA steps are reachable only from a successful password submission.
  const [step, setStep] = useState<AuthStep>(
    defaultTab === "signup" ? "signup" : "credentials",
  );
  // Email captured at the password step so the 2FA challenge can show
  // "Signing in as X · not you?" without the server having to include
  // identity fields in the half-auth response.
  const [twoFactorEmail, setTwoFactorEmail] = useState<string | null>(null);

  const next = typeof router.query.next === "string" ? router.query.next : undefined;
  const inviteToken =
    typeof router.query.invite === "string" ? router.query.invite : undefined;
  const registered = router.query.registered === "true";
  const reset = router.query.reset === "true";

  // If a logged-in user lands on /signin or /signup (e.g. via an old
  // bookmark), bounce them straight to wherever `next` points so they
  // don't have to look at a sign-in form they don't need.
  useEffect(() => {
    if (!isLoading && isAuthenticated) {
      router.replace(safeNextPath(next));
    }
  }, [isLoading, isAuthenticated, next, router]);

  const queryIndex = router.asPath.indexOf("?");
  const search = queryIndex >= 0 ? router.asPath.slice(queryIndex) : "";

  // Pick the title/subtitle/footer copy for the current step. The
  // signin/signup tabs share the COPY map shape; the 2FA steps come from
  // twoFactorCopy() (built per render because the subtitle includes the
  // captured email).
  const tabCopy = COPY[step === "signup" ? "signup" : "signin"];
  const isTwoFactor = step === "totp" || step === "backup";
  const tfCopy = isTwoFactor ? twoFactorCopy(step, twoFactorEmail) : null;
  const cardCopy = tfCopy ?? { title: tabCopy.title, subtitle: tabCopy.subtitle };

  return (
    <div className="min-h-screen flex flex-col items-center justify-center bg-background px-4 py-10 gap-8">
      <AuraWordmark />
      <Card className="w-full max-w-lg overflow-hidden p-0">
        <CardHeader className="p-8 pb-6">
          <CardTitle className="text-3xl font-bold">{cardCopy.title}</CardTitle>
          <CardDescription className="text-base">{cardCopy.subtitle}</CardDescription>
        </CardHeader>
        <CardContent className="p-8 pt-2">
          {step === "signup" ? (
            <SignUpForm inviteToken={inviteToken} next={next} />
          ) : step === "credentials" ? (
            <SignInForm
              next={next}
              registered={registered}
              reset={reset}
              onTwoFactorRequired={(email) => {
                setTwoFactorEmail(email);
                setStep("totp");
              }}
            />
          ) : (
            <TwoFactorChallengeForm
              next={next}
              mode={step}
              email={twoFactorEmail}
              onCancel={() => {
                setTwoFactorEmail(null);
                setStep("credentials");
              }}
            />
          )}
        </CardContent>
        <div className="border-t border-border bg-background px-8 py-5 text-center text-sm text-muted-foreground">
          {isTwoFactor && tfCopy ? (
            <button
              type="button"
              onClick={() => setStep(step === "totp" ? "backup" : "totp")}
              className="text-primary font-semibold hover:text-foreground"
              data-testid="2fa-mode-toggle"
            >
              {tfCopy.switchCta}
            </button>
          ) : (
            <>
              {tabCopy.switchLabel}{" "}
              <Link
                href={`${tabCopy.switchPath}${search}`}
                className="text-primary font-semibold hover:text-foreground"
              >
                {tabCopy.switchCta}
              </Link>
            </>
          )}
        </div>
      </Card>
      <p className="text-xs text-muted-foreground tracking-wide">
        <a href="/privacy" className="hover:text-foreground">privacy</a>
        <span className="mx-2 opacity-60">•</span>
        <a href="/terms" className="hover:text-foreground">terms</a>
      </p>
    </div>
  );
};

export default AuthCard;
