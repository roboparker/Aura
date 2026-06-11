import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/router";
import { CheckCircle2, Loader2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { useSignupStatus } from "@/lib/useSignupStatus";
import { isSafeNextPath, safeNextPath } from "@/lib/authRedirect";
import { landingPathFor, readLanding } from "@/lib/landingDestination";
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
  // Waitlist-mode signup skips the avatar-color step: collect → register →
  // "you're on the list" confirmation. submitting unmounts the form so a
  // double-click can't double-submit.
  | "waitlist-submitting"
  | "waitlist-done"
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

/** Header copy when the instance is in waitlist mode (signup form step). */
const WAITLIST_SIGNUP_COPY: { title: string; subtitle: string } = {
  title: "Join the Aura waitlist",
  subtitle:
    "We're onboarding people in batches. Claim your spot and we'll email you the moment your account is ready.",
};

/** Header copy for the post-join confirmation step. */
const WAITLIST_DONE_COPY: { title: string; subtitle: string } = {
  title: "You're on the list",
  subtitle: "Thanks for signing up — we'll be in touch soon.",
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
  const { isAuthenticated, isLoading, register, user } = useAuth();
  const { selectActiveSpaceById } = useActiveSpace();
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

  // Whether the instance is in waitlist mode — drives the signup copy/flow and
  // the footer CTA on both entry points. Invite signups always run the normal
  // flow (they bypass the gate), so an invite token forces it off. Errors fall
  // back to open signups; the server still enforces the real gate.
  const { waitlistEnabled } = useSignupStatus();
  const waitlistMode = waitlistEnabled && !inviteToken;

  useEffect(() => {
    // Authenticated users normally don't need /signin or /signup, so
    // we bounce them to wherever `next` points. The one exception is
    // /signup with an `?invite=` token — that user might be signed in
    // as the wrong account and needs to see the email-mismatch screen
    // (or accept-as-current-user if they happen to match). InviteSignup
    // handles the branching internally.
    if (!isLoading && isAuthenticated && !inviteToken) {
      // No deep link → fall back to the user's "Start page" preference,
      // priming the active space for the "specific space" choice.
      const landing = readLanding(user?.preferences);
      if (!isSafeNextPath(next) && landing.page === "space") {
        selectActiveSpaceById(landing.spaceId);
      }
      router.replace(safeNextPath(next, landingPathFor(landing)));
    }
  }, [
    isLoading,
    isAuthenticated,
    next,
    router,
    inviteToken,
    user?.preferences,
    selectActiveSpaceById,
  ]);

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

  // The footer's "switch" CTA points either at signup or signin. When it leads
  // to signup and the instance is waitlisted, label it "Join the waitlist" so
  // the signin page's footer reads the same as every other public CTA.
  const switchCta =
    waitlistMode && tabCopy.switchPath === "/signup"
      ? "Join the waitlist"
      : tabCopy.switchCta;

  const cardCopy: { title: string; subtitle: string } | null = (() => {
    if (tfCopy) return { title: tfCopy.title, subtitle: tfCopy.subtitle };
    if (step === "signup-color") return SIGNUP_COLOR_COPY;
    if (step === "signup-provisioning") return null;
    if (step === "waitlist-submitting") return null;
    if (step === "waitlist-done") return WAITLIST_DONE_COPY;
    if (waitlistMode && step === "signup-form") return WAITLIST_SIGNUP_COPY;
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
    step !== "waitlist-submitting" &&
    step !== "waitlist-done" &&
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
            <div className="space-y-4">
              {signupError && (
                <Alert variant="destructive" data-testid="signup-error">
                  <AlertDescription>{signupError}</AlertDescription>
                </Alert>
              )}
              <SignUpForm
                inviteToken={inviteToken}
                submitLabel={waitlistMode ? "Join the waitlist" : undefined}
                onCollected={async (values, token) => {
                  setSignupPayload({ values, inviteToken: token });
                  setSignupError(null);
                  if (!waitlistMode) {
                    setStep("signup-color");
                    return;
                  }
                  // Waitlist mode: skip the avatar-color step and register
                  // straight away. Switching the step here unmounts the form
                  // before the await resolves, so it can't double-submit; the
                  // backend assigns a random avatar color.
                  setStep("waitlist-submitting");
                  try {
                    await register({
                      email: values.email,
                      password: values.password,
                      givenName: values.givenName,
                      familyName: values.familyName,
                      inviteToken: token,
                    });
                    setStep("waitlist-done");
                  } catch (err) {
                    setSignupError(
                      err instanceof Error ? err.message : "Couldn't join the waitlist.",
                    );
                    setStep("signup-form");
                  }
                }}
              />
            </div>
          )}

          {step === "waitlist-submitting" && (
            <div className="flex flex-col items-center gap-3 py-8 text-center">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              <p className="text-sm text-muted-foreground">Joining the waitlist…</p>
            </div>
          )}

          {step === "waitlist-done" && (
            <div className="flex flex-col items-center gap-4 py-4 text-center">
              <CheckCircle2 className="h-12 w-12 text-primary" />
              <p className="text-sm text-muted-foreground">
                We&apos;ll email{" "}
                <span className="font-medium text-foreground">
                  {signupPayload?.values.email}
                </span>{" "}
                as soon as your account is ready. You can sign in any time to
                check your status.
              </p>
              <Button asChild className="w-full">
                <Link href="/signin">Go to sign in</Link>
              </Button>
            </div>
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
                  {switchCta}
                </Link>
              </>
            )}
          </div>
        )}
      </Card>
      <p className="text-xs text-muted-foreground tracking-wide">
        <Link href="/privacy" className="hover:text-foreground">privacy</Link>
        <span className="mx-2 opacity-60">•</span>
        <Link href="/terms" className="hover:text-foreground">terms</Link>
      </p>
    </div>
  );
};

export default AuthCard;
