import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { Formik, Form } from "formik";
import { Check, Clock } from "lucide-react";
import {
  PasswordResetTokenError,
  useAuth,
  type PasswordResetTokenCode,
} from "@/contexts/AuthContext";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { FormikField } from "@/components/ui/formik-field";
import AuraWordmark from "@/components/auth/AuraWordmark";
import PasswordStrengthMeter from "@/components/auth/PasswordStrengthMeter";
import {
  MIN_PASSWORD_LENGTH,
  MIN_PASSWORD_STRENGTH,
  estimatePasswordStrength,
} from "@/lib/passwordStrength";

interface ResetPasswordValues {
  newPassword: string;
  confirmPassword: string;
}

const SUCCESS_REDIRECT_MS = 1400;

const validate = (values: ResetPasswordValues) => {
  const errors: Partial<ResetPasswordValues> = {};

  if (!values.newPassword) {
    errors.newPassword = "New password is required.";
  } else if (values.newPassword.length < MIN_PASSWORD_LENGTH) {
    errors.newPassword = `Password must be at least ${MIN_PASSWORD_LENGTH} characters.`;
  } else if (
    estimatePasswordStrength(values.newPassword) < MIN_PASSWORD_STRENGTH
  ) {
    errors.newPassword =
      "Too weak. Use a longer mix of words, numbers, and symbols.";
  }

  if (!values.confirmPassword) {
    errors.confirmPassword = "Please confirm your password.";
  } else if (values.newPassword !== values.confirmPassword) {
    errors.confirmPassword = "Passwords do not match.";
  }

  return errors;
};

const PageShell = ({ children }: { children: React.ReactNode }) => (
  <div className="min-h-screen flex flex-col items-center justify-center bg-background px-4 py-10 gap-8">
    <AuraWordmark />
    <Card className="w-full max-w-lg overflow-hidden p-0">{children}</Card>
    <p className="text-xs text-muted-foreground tracking-wide">
      <Link href="/privacy" className="hover:text-foreground">
        privacy
      </Link>
      <span className="mx-2 opacity-60">•</span>
      <Link href="/terms" className="hover:text-foreground">
        terms
      </Link>
    </p>
  </div>
);

/**
 * Visual + copy + CTA variants for the "this link can't be used" card.
 * The reason discriminator is `PasswordResetTokenCode` from the API
 * (plus a `no_token` synthesized in the client when the URL didn't
 * carry a token at all).
 */
type InvalidLinkReason = PasswordResetTokenCode | "no_token";

interface InvalidLinkProps {
  reason: InvalidLinkReason;
}

const INVALID_LINK_VARIANTS: Record<
  InvalidLinkReason,
  {
    icon: typeof Clock;
    iconTone: "destructive" | "foreground";
    title: string;
    body: string;
    /** Primary CTA — what we think the user most likely needs next. */
    primary: { href: string; label: string };
    /** Secondary CTA. Hidden when label is empty. */
    secondary: { href: string; label: string };
  }
> = {
  // Token was found, but its TTL elapsed. Most likely fix: ask for a
  // fresh link. The clock icon matches the "time ran out" framing.
  token_expired: {
    icon: Clock,
    iconTone: "destructive",
    title: "This reset link expired",
    body: "Reset links work for 1 hour. Request a new one and try again.",
    primary: { href: "/forgot-password", label: "Request a new link" },
    secondary: { href: "/signin", label: "Back to sign in" },
  },
  // Token was already used. The most likely shape of this user is "I
  // just reset my password and I'm trying again" — push them at sign-in
  // first, and only offer a fresh link as the fallback.
  token_used: {
    icon: Check,
    iconTone: "foreground",
    title: "This link has already been used",
    body: "Each reset link works exactly once. If you set a new password, sign in with it. If this wasn't you, contact your workspace admin.",
    primary: { href: "/signin", label: "Sign in" },
    secondary: { href: "/forgot-password", label: "Request a new link" },
  },
  // Token didn't match anything we issued — usually a mangled URL, a
  // token that's been invalidated by a newer request, or someone trying
  // their luck.
  token_invalid: {
    icon: Clock,
    iconTone: "destructive",
    title: "This reset link can't be used",
    body: "We couldn't recognise that reset link. Request a new one and try again.",
    primary: { href: "/forgot-password", label: "Request a new link" },
    secondary: { href: "/signin", label: "Back to sign in" },
  },
  // No `?token=...` query at all — typically a user typing /reset-
  // password directly into the address bar.
  no_token: {
    icon: Clock,
    iconTone: "destructive",
    title: "This reset link is missing",
    body: "Open the reset link from your email to set a new password.",
    primary: { href: "/forgot-password", label: "Request a new link" },
    secondary: { href: "/signin", label: "Back to sign in" },
  },
};

const InvalidLinkCard = ({ reason }: InvalidLinkProps) => {
  const variant = INVALID_LINK_VARIANTS[reason];
  const Icon = variant.icon;
  const iconClasses =
    variant.iconTone === "destructive"
      ? "bg-destructive/15 text-destructive"
      : "bg-foreground/10 text-foreground";

  // One stable testid for the legacy "any rejection" assertion plus a
  // reason-specific one so future tests can pin to a particular state
  // without having to re-derive it from copy.
  return (
    <CardContent
      className="p-8 text-center space-y-5"
      data-testid="reset-password-error"
      data-reason={reason}
    >
      <div
        className={`mx-auto flex h-12 w-12 items-center justify-center rounded-full ${iconClasses}`}
        aria-hidden="true"
      >
        <Icon className="h-6 w-6" />
      </div>
      <div className="space-y-2">
        <h1 className="text-2xl font-bold text-foreground">{variant.title}</h1>
        <p className="text-sm text-muted-foreground">{variant.body}</p>
      </div>
      <div className="space-y-3">
        <Button asChild className="w-full h-11">
          <Link href={variant.primary.href}>{variant.primary.label}</Link>
        </Button>
        <Button asChild variant="secondary" className="w-full h-11">
          <Link href={variant.secondary.href}>{variant.secondary.label}</Link>
        </Button>
      </div>
    </CardContent>
  );
};

const ResetPassword = () => {
  const { resetPassword } = useAuth();
  const router = useRouter();
  const [succeeded, setSucceeded] = useState(false);
  const [tokenRejection, setTokenRejection] =
    useState<PasswordResetTokenCode | null>(null);
  const token =
    typeof router.query.token === "string" ? router.query.token : "";

  // Brief "Password updated" pause before bouncing to /signin?reset=true.
  // The signin page already shows the green confirmation banner; this
  // intermediate view just keeps the transition from looking like a flash.
  useEffect(() => {
    if (!succeeded) return;
    const id = setTimeout(() => {
      router.push("/signin?reset=true");
    }, SUCCESS_REDIRECT_MS);
    return () => clearTimeout(id);
  }, [succeeded, router]);

  if (!router.isReady) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  // Pick which screen to render. The token-rejection branch is a
  // separate state (rather than reading off status) so the user lands
  // squarely on a card with a "what to do next" CTA instead of staring
  // at a destructive alert above a form they can't make work.
  const renderBody = () => {
    if (succeeded) {
      return (
        <CardContent
          className="p-8 text-center space-y-5"
          data-testid="reset-password-done"
        >
          <div
            className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary/15 text-primary"
            aria-hidden="true"
          >
            <Check className="h-6 w-6" />
          </div>
          <div className="space-y-2">
            <h1 className="text-2xl font-bold text-foreground">
              Password updated
            </h1>
            <p className="text-sm text-muted-foreground">
              Redirecting you to sign in…
            </p>
          </div>
        </CardContent>
      );
    }

    if (tokenRejection) {
      return <InvalidLinkCard reason={tokenRejection} />;
    }

    if (!token) {
      // We treat "no token in URL" as a separate visual variant — same
      // testid for back-compat with the e2e suite, but its own
      // `data-reason` and copy.
      return (
        <div data-testid="reset-password-missing-token">
          <InvalidLinkCard reason="no_token" />
        </div>
      );
    }

    return (
      <>
        <CardHeader className="p-8 pb-4">
          <CardTitle className="text-3xl font-bold">
            Set a new password
          </CardTitle>
          <CardDescription className="text-base">
            Choose a strong password you haven&apos;t used before.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-8 pt-2">
          <Formik<ResetPasswordValues>
            initialValues={{ newPassword: "", confirmPassword: "" }}
            validate={validate}
            onSubmit={async (values, { setSubmitting, setStatus }) => {
              try {
                await resetPassword(token, values.newPassword);
                setSucceeded(true);
              } catch (err) {
                // Token-shaped errors swap the whole screen; everything
                // else (length, strength, network) renders inline so the
                // user can fix the input and retry without losing it.
                if (err instanceof PasswordResetTokenError) {
                  setTokenRejection(err.code);
                  return;
                }
                setStatus(
                  err instanceof Error
                    ? err.message
                    : "Failed to reset password.",
                );
              } finally {
                setSubmitting(false);
              }
            }}
          >
            {({ isSubmitting, status, values }) => (
              <Form className="space-y-5" noValidate>
                {status && (
                  <Alert variant="destructive">
                    <AlertDescription>{status}</AlertDescription>
                  </Alert>
                )}

                <FormikField
                  name="newPassword"
                  type="password"
                  label="New password"
                  autoComplete="new-password"
                  inputClassName="h-11 text-base"
                  labelAddon={
                    <span className="text-xs text-muted-foreground">
                      min 12 chars
                    </span>
                  }
                />
                <PasswordStrengthMeter password={values.newPassword} />

                <FormikField
                  name="confirmPassword"
                  type="password"
                  label="Confirm new password"
                  autoComplete="new-password"
                  inputClassName="h-11 text-base"
                />

                <Button
                  type="submit"
                  disabled={isSubmitting}
                  className="w-full h-12 text-base font-semibold"
                >
                  {isSubmitting ? "Setting password…" : "Set password"}
                </Button>

                <p className="text-xs text-muted-foreground leading-relaxed">
                  <span
                    className="mr-2 inline-block h-1.5 w-1.5 rounded-full bg-muted-foreground/60 align-middle"
                    aria-hidden="true"
                  />
                  After reset — you&apos;ll sign in with your new password on
                  the next screen.
                </p>
              </Form>
            )}
          </Formik>
        </CardContent>
      </>
    );
  };

  return (
    <>
      <Head>
        <title>Set a new password — Aura</title>
      </Head>
      <PageShell>{renderBody()}</PageShell>
    </>
  );
};

export default ResetPassword;
