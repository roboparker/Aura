import Link from "next/link";
import { useEffect, useState } from "react";
import { Formik, Form } from "formik";
import { ENTRYPOINT } from "@/config/entrypoint";
import { fetchWithTimeout } from "@/lib/fetchWithTimeout";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";
import {
  MIN_PASSWORD_LENGTH,
  MIN_PASSWORD_STRENGTH,
  estimatePasswordStrength,
} from "@/lib/passwordStrength";
import PasswordStrengthMeter from "./PasswordStrengthMeter";

export interface SignUpFormValues {
  givenName: string;
  familyName: string;
  email: string;
  password: string;
}

interface InviteContext {
  email: string;
  groups: { id: string; title: string; invitedBy: string }[];
  spaces?: { id: string; name: string; invitedBy: string; role: string }[];
  expiresAt: string;
}

const validate = (values: SignUpFormValues) => {
  const errors: Partial<Record<keyof SignUpFormValues, string>> = {};

  if (!values.givenName.trim()) {
    errors.givenName = "Given name is required.";
  } else if (values.givenName.length > 100) {
    errors.givenName = "Given name must be 100 characters or fewer.";
  }

  if (!values.familyName.trim()) {
    errors.familyName = "Family name is required.";
  } else if (values.familyName.length > 100) {
    errors.familyName = "Family name must be 100 characters or fewer.";
  }

  if (!values.email) {
    errors.email = "Email is required.";
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
    errors.email = "Invalid email address.";
  }

  if (!values.password) {
    errors.password = "Password is required.";
  } else if (values.password.length < MIN_PASSWORD_LENGTH) {
    errors.password = `Password must be at least ${MIN_PASSWORD_LENGTH} characters.`;
  } else if (estimatePasswordStrength(values.password) < MIN_PASSWORD_STRENGTH) {
    errors.password = "Too weak. Use a longer mix of words, numbers, and symbols.";
  }

  return errors;
};

interface Props {
  /** Optional `?invite=` token from the URL — drives the invite banner. */
  inviteToken?: string;
  /**
   * Called when the form passes validation. Parent (AuthCard) holds the
   * collected payload and transitions to the avatar-color step before
   * actually POSTing to /users — sign-up doesn't fire until the user
   * picks their color.
   */
  onCollected: (values: SignUpFormValues, inviteToken?: string) => void;
}

const SignUpForm = ({ inviteToken, onCollected }: Props) => {
  const [invite, setInvite] = useState<InviteContext | null>(null);
  const [inviteLoading, setInviteLoading] = useState(false);
  const [inviteError, setInviteError] = useState<string | null>(null);

  useEffect(() => {
    if (!inviteToken) {
      setInvite(null);
      setInviteError(null);
      return;
    }

    let cancelled = false;
    setInviteLoading(true);
    setInviteError(null);
    (async () => {
      try {
        const res = await fetchWithTimeout(
          `${ENTRYPOINT}/invites/${encodeURIComponent(inviteToken)}`,
        );
        if (!res.ok) {
          if (!cancelled) {
            setInvite(null);
            setInviteError(
              "This invitation link is invalid or has expired. You can still sign up below.",
            );
          }
          return;
        }
        const data: InviteContext = await res.json();
        if (!cancelled) setInvite(data);
      } catch {
        if (!cancelled) {
          setInvite(null);
          setInviteError(
            "Couldn't verify your invitation. You can still sign up below.",
          );
        }
      } finally {
        if (!cancelled) setInviteLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [inviteToken]);

  return (
    <div className="space-y-4">
      {inviteLoading && (
        <p className="text-sm text-muted-foreground text-center">
          Checking your invitation…
        </p>
      )}

      {inviteError && (
        <Alert>
          <AlertDescription>{inviteError}</AlertDescription>
        </Alert>
      )}

      {invite && (
        <Alert data-testid="invite-context">
          <AlertDescription>
            <p className="font-semibold">You&apos;ve been invited to join:</p>
            <ul className="list-disc list-inside mt-1">
              {invite.spaces?.map((space) => (
                <li key={`space-${space.id}`} data-testid="invite-context-space">
                  <span className="font-medium">{space.name}</span>{" "}
                  <span className="text-primary/80">
                    (space — invited by {space.invitedBy})
                  </span>
                </li>
              ))}
              {invite.groups.map((group) => (
                <li key={`group-${group.id}`} data-testid="invite-context-group">
                  <span className="font-medium">{group.title}</span>{" "}
                  <span className="text-primary/80">
                    (group — invited by {group.invitedBy})
                  </span>
                </li>
              ))}
            </ul>
            <p className="mt-2 text-xs">
              Sign up with <strong>{invite.email}</strong> and we&apos;ll add you
              automatically.
            </p>
          </AlertDescription>
        </Alert>
      )}

      <Formik<SignUpFormValues>
        enableReinitialize
        initialValues={{
          givenName: "",
          familyName: "",
          email: invite?.email ?? "",
          password: "",
        }}
        validate={validate}
        onSubmit={(values, { setSubmitting }) => {
          // Don't POST yet — hand the collected payload to the parent so
          // it can run the avatar-color step before registering.
          onCollected(
            {
              ...values,
              givenName: values.givenName.trim(),
              familyName: values.familyName.trim(),
            },
            invite ? inviteToken : undefined,
          );
          setSubmitting(false);
        }}
      >
        {({ errors, touched, submitCount, values, isSubmitting }) => {
          // Show a top-of-form summary once the user has tried to submit
          // at least once and we still have outstanding errors — mirrors
          // the mockup's "Fix the highlighted fields" callout. Counting
          // errors instead of just listing them keeps the message short
          // on small viewports.
          const visibleErrors =
            submitCount > 0
              ? (Object.keys(errors) as (keyof SignUpFormValues)[]).filter(
                  (k) => touched[k] || submitCount > 0,
                )
              : [];

          return (
            <Form className="space-y-4" noValidate>
              {visibleErrors.length > 0 && (
                <Alert variant="destructive" data-testid="signup-error-summary">
                  <AlertDescription>
                    <p className="font-semibold">Fix the highlighted fields</p>
                    <p className="text-xs mt-1">
                      {visibleErrors.length} issue
                      {visibleErrors.length === 1 ? "" : "s"} to resolve before you can
                      continue.
                    </p>
                  </AlertDescription>
                </Alert>
              )}

              <div className="grid grid-cols-2 gap-3">
                <FormikField
                  name="givenName"
                  type="text"
                  autoComplete="given-name"
                  label="Given name"
                />
                <FormikField
                  name="familyName"
                  type="text"
                  autoComplete="family-name"
                  label="Family name"
                />
              </div>

              <FormikField
                name="email"
                type="email"
                label="Work email"
                placeholder="you@example.com"
                autoComplete="email"
                readOnly={!!invite}
                inputClassName={invite ? "bg-muted text-muted-foreground" : undefined}
                description={
                  invite ? "Locked to the email your invitation was sent to." : undefined
                }
              />

              <FormikField
                name="password"
                type="password"
                label="Password"
                placeholder=""
                autoComplete="new-password"
                labelAddon={
                  // 12 (not MIN_PASSWORD_LENGTH = 8) is what the hint
                  // shows because the MEDIUM strength floor effectively
                  // rejects everything shorter than ~12 chars even when
                  // the 8-char length check would let it through. Telling
                  // the user the technically-allowed minimum sets them up
                  // for a "but I had 8 characters" rejection — surface
                  // the practical floor instead.
                  <span className="text-xs text-muted-foreground">min 12 chars</span>
                }
              />
              <PasswordStrengthMeter password={values.password} />

              <Button type="submit" disabled={isSubmitting} className="w-full">
                Create account
              </Button>

              <p className="text-xs text-muted-foreground text-center">
                By continuing you agree to Aura&apos;s{" "}
                <Link href="/terms" className="text-primary font-semibold hover:text-foreground">
                  Terms
                </Link>{" "}
                and{" "}
                <Link href="/privacy" className="text-primary font-semibold hover:text-foreground">
                  Privacy Policy
                </Link>
                .
              </p>
            </Form>
          );
        }}
      </Formik>
    </div>
  );
};

export default SignUpForm;
