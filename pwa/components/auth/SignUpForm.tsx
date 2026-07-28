import { useEffect, useState } from "react";
import { Formik, Form } from "formik";
import { ENTRYPOINT } from "@/config/entrypoint";
import { fetchWithTimeout } from "@/lib/fetchWithTimeout";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import SsoButtons from "@/components/auth/SsoButtons";
import { FormikField } from "@/components/ui/formik-field";
import { FormikFocusError, findFieldControl } from "@/components/ui/formik-focus-error";
import {
  MIN_PASSWORD_LENGTH,
  MIN_PASSWORD_STRENGTH,
  estimatePasswordStrength,
} from "@/lib/passwordStrength";
import PasswordStrengthMeter from "./PasswordStrengthMeter";
import TermsConsentField from "./TermsConsentField";

export interface SignUpFormValues {
  givenName: string;
  familyName: string;
  email: string;
  password: string;
  acceptTerms: boolean;
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

  if (!values.acceptTerms) {
    errors.acceptTerms = "Please agree to the Terms and Privacy Policy to continue.";
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
  /**
   * Submit-button label. Defaults to "Create account"; the signup page
   * passes "Join the waitlist" when the instance is in waitlist mode.
   */
  submitLabel?: string;
}

const SignUpForm = ({ inviteToken, onCollected, submitLabel = "Create account" }: Props) => {
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
          acceptTerms: false,
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
              <FormikFocusError />
              {visibleErrors.length > 0 && (
                // Focus lands here after a failed submit (see FormikFocusError),
                // so the user hears the whole picture before being dropped into
                // a single field. tabIndex={-1} makes it programmatically
                // focusable without adding a tab stop; role="alert" announces it
                // if it appears while focus is elsewhere.
                <Alert
                  variant="destructive"
                  data-testid="signup-error-summary"
                  data-error-summary=""
                  tabIndex={-1}
                  role="alert"
                  className="focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <AlertDescription>
                    <p className="font-semibold">Fix the highlighted fields</p>
                    <p className="text-xs mt-1">
                      {visibleErrors.length} issue
                      {visibleErrors.length === 1 ? "" : "s"} to resolve before you can
                      continue.
                    </p>
                    <ul className="mt-2 space-y-0.5 text-xs">
                      {visibleErrors.map((key) => (
                        <li key={key}>
                          {/* A button, not an anchor: this moves focus, it
                              doesn't navigate. Resolution is by `name`, not
                              `id` — id schemes vary per control (the consent
                              checkbox renders as `consent-acceptTerms`), so an
                              id-based href would silently be a dead link. */}
                          <button
                            type="button"
                            className="text-left underline underline-offset-2"
                            onClick={(e) => {
                              const form = e.currentTarget.closest("form");
                              if (form) findFieldControl(form, key)?.focus();
                            }}
                          >
                            {errors[key]}
                          </button>
                        </li>
                      ))}
                    </ul>
                  </AlertDescription>
                </Alert>
              )}

              <div className="grid grid-cols-2 gap-3">
                <FormikField
                  required
                  name="givenName"
                  type="text"
                  autoComplete="given-name"
                  label="Given name"
                />
                <FormikField
                  required
                  name="familyName"
                  type="text"
                  autoComplete="family-name"
                  label="Family name"
                />
              </div>

              <FormikField
                required
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
                required
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

              <TermsConsentField />

              <Button type="submit" loading={isSubmitting} className="w-full">
                {submitLabel}
              </Button>
              <SsoButtons verb="Sign up" />
            </Form>
          );
        }}
      </Formik>
    </div>
  );
};

export default SignUpForm;
