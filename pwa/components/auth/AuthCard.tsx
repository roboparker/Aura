import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/router";
import { useAuth } from "@/contexts/AuthContext";
import { isSafeNextPath, safeNextPath } from "@/lib/authRedirect";
import { AVATAR_PALETTE } from "@/lib/avatarPalette";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import AuraWordmark from "./AuraWordmark";
import AvatarColorPicker from "./AvatarColorPicker";
import InviteSignup from "./InviteSignup";
import SignInForm from "./SignInForm";
import SignUpForm, { type SignUpFormValues } from "./SignUpForm";
import SignUpProvisioning from "./SignUpProvisioning";
import TwoFactorChallengeForm, {
  type TwoFactorMode,
} from "./TwoFactorChallengeForm";

type Tab = "signin" | "signup";

interface Props {
  /** Which form this page entry point renders. */
  defaultTab: Tab;
}

/**
 * Current step of the sign-in/up flow. The 2FA modes are reached only
 * via the credentials step. The signup substeps (form → color →
 * provisioning) are tracked here so the card header/footer can swap
 * in lockstep with the body, and so the collected form payload can
 * live in one place across the multi-step transition.
 */
type AuthStep =
  | "credentials"
  | "signup-form"
  | "signup-color"
  | "signup-provisioning"
  | "totp"
  | "backup";

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
    title: "Create your Aura account",
    subtitle: "Free for individual use. Add teammates anytime.",
    switchLabel: "Already have an account?",
    switchCta: "Sign in",
    switchPath: "/signin",
  },
};

/** Title/subtitle for the avatar-color step of sign-up. */
const SIGNUP_COLOR_COPY: { title: string; subtitle: string } = {
  title: "Pick your avatar color",
  subtitle:
    "Your initials show up everywhere in Aura. Pick a color you'll recognize at a glance — you can change it later.",
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
  const { isAuthenticated, isLoading, register } = useAuth();
  const [step, setStep] = useState<AuthStep>(
    defaultTab === "signup" ? "signup-form" : "credentials",
  );
  const [twoFactorEmail, setTwoFactorEmail] = useState<string | null>(null);

  // Sign-up multi-step state. The form payload is captured on step 1
  // and held here so step 2 can POST it with the chosen color; the
  // initial color seed is a random palette pick so the preview cards
  // on step 2 don't look empty before the user clicks anything.
  const [signupPayload, setSignupPayload] = useState<{
    values: SignUpFormValues;
    inviteToken?: string;
  } | null>(null);
  const [signupColor, setSignupColor] = useState<string>(
    () => AVATAR_PALETTE[Math.floor(Math.random() * AVATAR_PALETTE.length)],
  );
  const [signupError, setSignupError] = useState<string | null>(null);
  const [isRegistering, setIsRegistering] = useState(false);

  const next = typeof router.query.next === "string" ? router.query.next : undefined;
  const inviteToken =
    typeof router.query.invite === "string" ? router.query.invite : undefined;
  const registered = router.query.registered === "true";
  const reset = router.query.reset === "true";

  useEffect(() => {
    // Authenticated users normally don't need /signin or /signup, so
    // we bounce them to wherever `next` points. The one exception is
    // /signup with an `?invite=` token — that user might be signed in
    // as the wrong account and needs to see the email-mismatch screen
    // (or accept-as-current-user if they happen to match). InviteSignup
    // handles the branching internally.
    if (!isLoading && isAuthenticated && !inviteToken) {
      router.replace(safeNextPath(next));
    }
  }, [isLoading, isAuthenticated, next, router, inviteToken]);

  const queryIndex = router.asPath.indexOf("?");
  const search = queryIndex >= 0 ? router.asPath.slice(queryIndex) : "";

  const initials = useMemo(() => {
    if (!signupPayload) return "";
    const g = signupPayload.values.givenName.trim()[0] ?? "";
    const f = signupPayload.values.familyName.trim()[0] ?? "";
    return (g + f).toUpperCase() || "?";
  }, [signupPayload]);

  // Resolve the card header copy from whichever step we're in. Signup
  // has three substeps: the form (re-uses the `signup` COPY entry),
  // the color picker (its own copy), and the provisioning animation
  // (no card header — the success summary lives in the body instead,
  // so we hand back null and the renderer hides the CardHeader).
  const tabCopy = COPY[step === "credentials" ? "signin" : "signup"];
  const isTwoFactor = step === "totp" || step === "backup";
  const tfCopy = isTwoFactor ? twoFactorCopy(step, twoFactorEmail) : null;

  const cardCopy: { title: string; subtitle: string } | null = (() => {
    if (tfCopy) return { title: tfCopy.title, subtitle: tfCopy.subtitle };
    if (step === "signup-color") return SIGNUP_COLOR_COPY;
    if (step === "signup-provisioning") return null;
    return { title: tabCopy.title, subtitle: tabCopy.subtitle };
  })();

  const submitSignup = async (color: string) => {
    if (!signupPayload) return;
    setIsRegistering(true);
    setSignupError(null);
    try {
      await register({
        email: signupPayload.values.email,
        password: signupPayload.values.password,
        givenName: signupPayload.values.givenName,
        familyName: signupPayload.values.familyName,
        inviteToken: signupPayload.inviteToken,
        personalizedColor: color,
      });
      setStep("signup-provisioning");
    } catch (err) {
      setSignupError(err instanceof Error ? err.message : "Registration failed.");
    } finally {
      setIsRegistering(false);
    }
  };

  const onProvisioningDone = () => {
    const params = new URLSearchParams({ registered: "true" });
    if (isSafeNextPath(next)) params.set("next", next);
    router.push(`/signin?${params.toString()}`);
  };

  // Invite signup runs in its own component — it owns its own card
  // header (varies per outcome: expired / accepted / mismatch / form)
  // and footer (none — its CTAs cover the alt actions), so AuthCard
  // just provides the Card chrome around it.
  const isInviteSignup = step === "signup-form" && Boolean(inviteToken);

  // Footer hidden on signup-color (no relevant alt action),
  // signup-provisioning (we're routing away on a timer — offering an
  // out would just confuse the moment), and the entire invite flow
  // (each invite state has its own CTAs in the body).
  const showFooter =
    step !== "signup-color" &&
    step !== "signup-provisioning" &&
    !isInviteSignup;

  return (
    <div className="min-h-screen flex flex-col items-center justify-center bg-background px-4 py-10 gap-8">
      <AuraWordmark />
      <Card className="w-full max-w-lg overflow-hidden p-0">
        {cardCopy && !isInviteSignup && (
          <CardHeader className="p-8 pb-6">
            <CardTitle className="text-3xl font-bold">{cardCopy.title}</CardTitle>
            <CardDescription className="text-base">{cardCopy.subtitle}</CardDescription>
          </CardHeader>
        )}
        {isInviteSignup && inviteToken ? (
          <InviteSignup token={inviteToken} next={next} />
        ) : (
        <CardContent className={cardCopy ? "p-8 pt-2" : "p-8"}>
          {step === "signup-form" && (
            <SignUpForm
              inviteToken={inviteToken}
              onCollected={(values, token) => {
                setSignupPayload({ values, inviteToken: token });
                setSignupError(null);
                setStep("signup-color");
              }}
            />
          )}

          {step === "signup-color" && signupPayload && (
            <div className="space-y-5">
              {signupError && (
                <Alert variant="destructive" data-testid="signup-error">
                  <AlertDescription>{signupError}</AlertDescription>
                </Alert>
              )}
              <AvatarColorPicker
                initials={initials}
                selected={signupColor}
                onChange={setSignupColor}
              />
              <Button
                type="button"
                className="w-full"
                disabled={isRegistering}
                onClick={() => submitSignup(signupColor)}
                data-testid="signup-color-continue"
              >
                {isRegistering ? "Creating account…" : "Continue"}
              </Button>
            </div>
          )}

          {step === "signup-provisioning" && signupPayload && (
            <SignUpProvisioning
              initials={initials}
              color={signupColor}
              onDone={onProvisioningDone}
            />
          )}

          {step === "credentials" && (
            <SignInForm
              next={next}
              registered={registered}
              reset={reset}
              onTwoFactorRequired={(email) => {
                setTwoFactorEmail(email);
                setStep("totp");
              }}
            />
          )}

          {isTwoFactor && (
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
        )}
        {showFooter && (
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
        )}
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
