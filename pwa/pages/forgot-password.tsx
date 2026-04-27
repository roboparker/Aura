import Head from "next/head";
import Link from "next/link";
import { useState } from "react";
import { Formik, Form } from "formik";
import { useAuth } from "@/contexts/AuthContext";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FormikField } from "@/components/ui/formik-field";

interface ForgotPasswordValues {
  email: string;
}

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

  return (
    <>
      <Head>
        <title>Forgot Password - Aura</title>
      </Head>
      <div className="min-h-screen flex items-center justify-center bg-muted px-4 py-8">
        <Card className="w-full max-w-md">
          <CardHeader>
            <CardTitle className="text-2xl text-center">Forgot Password</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {submitted ? (
              <Alert variant="success" data-testid="forgot-password-success">
                <AlertDescription>
                  <p>If an account exists for that email, a reset link has been sent.</p>
                  <p className="mt-2">
                    <Link href="/signin" className="text-cyan-700 font-medium">
                      Back to Sign In
                    </Link>
                  </p>
                </AlertDescription>
              </Alert>
            ) : (
              <Formik<ForgotPasswordValues>
                initialValues={{ email: "" }}
                validate={validate}
                onSubmit={async (values, { setSubmitting, setStatus }) => {
                  try {
                    await requestPasswordReset(values.email);
                    setSubmitted(true);
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
                  <Form className="space-y-4" noValidate>
                    <p className="text-sm text-muted-foreground">
                      Enter your email and we&apos;ll send you a link to reset your password.
                    </p>

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
                    />

                    <Button type="submit" disabled={isSubmitting} className="w-full">
                      {isSubmitting ? "Sending..." : "Send Reset Link"}
                    </Button>

                    <p className="text-center text-sm text-muted-foreground">
                      <Link href="/signin" className="text-cyan-700 font-medium">
                        Back to Sign In
                      </Link>
                    </p>
                  </Form>
                )}
              </Formik>
            )}
          </CardContent>
        </Card>
      </div>
    </>
  );
};

export default ForgotPassword;
