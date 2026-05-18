import Link from "next/link";
import { useRouter } from "next/router";
import { useState } from "react";
import { Formik, Form } from "formik";
import { useAuth } from "@/contexts/AuthContext";
import { safeNextPath } from "@/lib/authRedirect";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";
import TwoFactorChallengeForm from "./TwoFactorChallengeForm";

interface SignInValues {
  email: string;
  password: string;
}

const validate = (values: SignInValues) => {
  const errors: Partial<SignInValues> = {};
  if (!values.email) errors.email = "Email is required.";
  if (!values.password) errors.password = "Password is required.";
  return errors;
};

interface Props {
  /** `?next=` from the URL — caller is responsible for passing it in. */
  next?: string;
  /** True when the user just registered (shown as a banner). */
  registered?: boolean;
  /** True when the user just reset their password. */
  reset?: boolean;
}

const SignInForm = ({ next, registered, reset }: Props) => {
  const { login } = useAuth();
  const router = useRouter();
  // Local state vs. URL state because the half-authenticated session is
  // already on a server cookie — there's nothing useful to put in the URL,
  // and a back-button to /signin should restart, not resume.
  const [twoFactorPrompt, setTwoFactorPrompt] = useState(false);
  const [ssoNotice, setSsoNotice] = useState<string | null>(null);

  if (twoFactorPrompt) {
    return (
      <TwoFactorChallengeForm
        next={next}
        onCancel={() => setTwoFactorPrompt(false)}
      />
    );
  }

  return (
    <div className="space-y-4">
      {registered && (
        <Alert>
          <AlertDescription>
            Account created successfully. Please sign in.
          </AlertDescription>
        </Alert>
      )}

      {reset && (
        <Alert data-testid="password-reset-success">
          <AlertDescription>
            Password reset successfully. Please sign in with your new password.
          </AlertDescription>
        </Alert>
      )}

      {ssoNotice && (
        <Alert>
          <AlertDescription>{ssoNotice}</AlertDescription>
        </Alert>
      )}

      <Formik<SignInValues>
        initialValues={{ email: "", password: "" }}
        validate={validate}
        onSubmit={async (values, { setSubmitting, setStatus }) => {
          try {
            const result = await login(values.email, values.password);
            if (result.requiresTwoFactor) {
              setTwoFactorPrompt(true);
              return;
            }
            router.push(safeNextPath(next));
          } catch (err) {
            setStatus(err instanceof Error ? err.message : "Sign in failed.");
          } finally {
            setSubmitting(false);
          }
        }}
      >
        {({ isSubmitting, status }) => (
          <Form className="space-y-5" noValidate>
            {status && (() => {
              const isCreds = /invalid credentials/i.test(status);
              const title = isCreds ? "Invalid email or password" : status;
              const description = isCreds
                ? "Double-check your credentials. After 5 failed attempts we'll pause sign-in briefly."
                : null;
              return (
                <Alert variant="destructive">
                  <svg
                    viewBox="0 0 10 10"
                    width="10"
                    height="10"
                    aria-hidden="true"
                    style={{
                      filter:
                        "drop-shadow(0 0 4px oklch(0.65 0.2 25 / 0.9)) drop-shadow(0 0 8px oklch(0.65 0.2 25 / 0.6))",
                    }}
                  >
                    <rect x="1" y="1" width="8" height="8" rx="2" fill="currentColor" />
                  </svg>
                  <AlertTitle>{title}</AlertTitle>
                  {description && <AlertDescription>{description}</AlertDescription>}
                </Alert>
              );
            })()}

            <FormikField
              name="email"
              type="email"
              label="Email"
              placeholder="you@example.com"
              autoComplete="email"
              inputClassName="h-11 text-base"
            />

            <FormikField
              name="password"
              type="password"
              label="Password"
              placeholder="Your password"
              autoComplete="current-password"
              inputClassName="h-11 text-base"
              labelAddon={
                <Link
                  href="/forgot-password"
                  className="text-sm text-primary font-semibold hover:text-foreground"
                >
                  Forgot password?
                </Link>
              }
            />

            <div className="space-y-3 pt-2">
              <Button
                type="submit"
                disabled={isSubmitting}
                className="w-full h-12 text-base font-semibold"
              >
                {isSubmitting ? "Signing in..." : "Sign in"}
              </Button>
              <Button
                type="button"
                variant="secondary"
                className="w-full h-12 text-base font-semibold text-foreground border border-border"
                onClick={() => setSsoNotice("SSO sign-in isn't enabled for this workspace yet.")}
              >
                Sign in with SSO
              </Button>
            </div>
          </Form>
        )}
      </Formik>
    </div>
  );
};

export default SignInForm;
