import Head from "next/head";
import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { Formik, Form } from "formik";
import { Mail } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
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

interface ForgotPasswordValues {
  email: string;
}

const RESEND_COOLDOWN_SECONDS = 60;

const validate = (values: ForgotPasswordValues) => {
  const errors: Partial<ForgotPasswordValues> = {};

  if (!values.email) {
    errors.email = "Email is required.";
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
    errors.email = "Invalid email address.";
  }

  return errors;
};

const ForgotPassword = () => {
  const { requestPasswordReset } = useAuth();
  const [submitted, setSubmitted] = useState(false);
  const [sentEmail, setSentEmail] = useState("");
  const [cooldown, setCooldown] = useState(0);
  const [resendError, setResendError] = useState<string | null>(null);
  const tickRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const startCooldown = useCallback(() => {
    setCooldown(RESEND_COOLDOWN_SECONDS);
    if (tickRef.current) clearInterval(tickRef.current);
    tickRef.current = setInterval(() => {
      setCooldown((s) => {
        if (s <= 1) {
          if (tickRef.current) {
            clearInterval(tickRef.current);
            tickRef.current = null;
          }
          return 0;
        }
        return s - 1;
      });
    }, 1000);
  }, []);

  useEffect(() => {
    return () => {
      if (tickRef.current) clearInterval(tickRef.current);
    };
  }, []);

  const handleResend = useCallback(async () => {
    if (cooldown > 0 || !sentEmail) return;
    setResendError(null);
    try {
      await requestPasswordReset(sentEmail);
      startCooldown();
    } catch (err) {
      setResendError(
        err instanceof Error ? err.message : "Couldn't resend the reset link.",
      );
    }
  }, [cooldown, sentEmail, requestPasswordReset, startCooldown]);

  return (
    <>
      <Head>
        <title>Reset your password — Aura</title>
      </Head>
      <div className="min-h-screen flex flex-col items-center justify-center bg-background px-4 py-10 gap-8">
        <AuraWordmark />
        <Card className="w-full max-w-lg overflow-hidden p-0">
          {submitted ? (
            <CardContent
              className="p-8 text-center space-y-5"
              data-testid="forgot-password-success"
            >
              <div
                className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary/15 text-primary"
                aria-hidden="true"
              >
                <Mail className="h-6 w-6" />
              </div>
              <div className="space-y-2">
                <h1 className="text-2xl font-bold text-foreground">
                  Check your email
                </h1>
                <p className="text-sm text-muted-foreground">
                  If{" "}
                  <span className="text-foreground font-medium">
                    {sentEmail}
                  </span>{" "}
                  has an Aura account, we just sent a reset link. It expires in
                  1 hour.
                </p>
              </div>

              {resendError && (
                <Alert variant="destructive">
                  <AlertDescription>{resendError}</AlertDescription>
                </Alert>
              )}

              <div className="flex items-center justify-center gap-2 text-xs text-muted-foreground">
                <button
                  type="button"
                  onClick={handleResend}
                  disabled={cooldown > 0}
                  className="rounded-md border border-border bg-muted/40 px-3 py-1.5 font-medium hover:text-foreground hover:border-foreground/40 disabled:cursor-not-allowed disabled:opacity-70"
                  data-testid="forgot-password-resend"
                >
                  {cooldown > 0
                    ? `Didn't get it? Resend in ${cooldown}s`
                    : "Resend reset link"}
                </button>
                <span className="rounded-md border border-border bg-muted/40 px-3 py-1.5 font-medium">
                  Check spam folder too
                </span>
              </div>

              <Button asChild variant="secondary" className="w-full h-11">
                <Link href="/signin">Back to sign in</Link>
              </Button>
            </CardContent>
          ) : (
            <>
              <CardHeader className="p-8 pb-4">
                <CardTitle className="text-3xl font-bold">
                  Reset your password
                </CardTitle>
                <CardDescription className="text-base">
                  We&apos;ll email you a link to set a new password. The link is
                  good for 1 hour.
                </CardDescription>
              </CardHeader>
              <CardContent className="p-8 pt-2">
                <Formik<ForgotPasswordValues>
                  initialValues={{ email: "" }}
                  validate={validate}
                  onSubmit={async (
                    values,
                    { setSubmitting, setStatus },
                  ) => {
                    try {
                      await requestPasswordReset(values.email);
                      setSentEmail(values.email);
                      setSubmitted(true);
                      startCooldown();
                    } catch (err) {
                      setStatus(
                        err instanceof Error
                          ? err.message
                          : "Failed to request password reset.",
                      );
                    } finally {
                      setSubmitting(false);
                    }
                  }}
                >
                  {({ isSubmitting, status }) => (
                    <Form className="space-y-5" noValidate>
                      {status && (
                        <Alert variant="destructive">
                          <AlertDescription>{status}</AlertDescription>
                        </Alert>
                      )}

                      <FormikField
                        name="email"
                        type="email"
                        label="Email"
                        placeholder="you@example.com"
                        autoComplete="email"
                        inputClassName="h-11 text-base"
                      />

                      <Button
                        type="submit"
                        disabled={isSubmitting}
                        className="w-full h-12 text-base font-semibold"
                      >
                        {isSubmitting ? "Sending…" : "Send reset link"}
                      </Button>

                      <p className="text-xs text-muted-foreground leading-relaxed">
                        <span
                          className="mr-2 inline-block h-1.5 w-1.5 rounded-full bg-muted-foreground/60 align-middle"
                          aria-hidden="true"
                        />
                        If you signed in via SSO, this won&apos;t apply —
                        contact your workspace admin.
                      </p>
                    </Form>
                  )}
                </Formik>
              </CardContent>
              <div className="border-t border-border bg-background px-8 py-5 text-center text-sm text-muted-foreground">
                <Link
                  href="/signin"
                  className="text-primary font-semibold hover:text-foreground"
                >
                  ← Back to sign in
                </Link>
              </div>
            </>
          )}
        </Card>
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
    </>
  );
};

export default ForgotPassword;
