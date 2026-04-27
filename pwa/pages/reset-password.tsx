import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { Formik, Form } from "formik";
import { useAuth } from "@/contexts/AuthContext";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FormikField } from "@/components/ui/formik-field";

interface ResetPasswordValues {
  newPassword: string;
  confirmPassword: string;
}

const validate = (values: ResetPasswordValues) => {
  const errors: Partial<ResetPasswordValues> = {};

  if (!values.newPassword) {
    errors.newPassword = "New password is required.";
  } else if (values.newPassword.length < 6) {
    errors.newPassword = "Password must be at least 6 characters.";
  }

  if (!values.confirmPassword) {
    errors.confirmPassword = "Please confirm your password.";
  } else if (values.newPassword !== values.confirmPassword) {
    errors.confirmPassword = "Passwords do not match.";
  }

  return errors;
};

const ResetPassword = () => {
  const { resetPassword } = useAuth();
  const router = useRouter();
  const token = typeof router.query.token === "string" ? router.query.token : "";

  if (!router.isReady) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading...</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Reset Password - Aura</title>
      </Head>
      <div className="min-h-screen flex items-center justify-center bg-muted px-4 py-8">
        <Card className="w-full max-w-md">
          <CardHeader>
            <CardTitle className="text-2xl text-center">Reset Password</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {!token ? (
              <Alert variant="destructive" data-testid="reset-password-missing-token">
                <AlertDescription>
                  <p>This reset link is missing or invalid.</p>
                  <p className="mt-2">
                    <Link href="/forgot-password" className="text-primary font-medium">
                      Request a new reset link
                    </Link>
                  </p>
                </AlertDescription>
              </Alert>
            ) : (
              <Formik<ResetPasswordValues>
                initialValues={{ newPassword: "", confirmPassword: "" }}
                validate={validate}
                onSubmit={async (values, { setSubmitting, setStatus }) => {
                  try {
                    await resetPassword(token, values.newPassword);
                    router.push("/signin?reset=true");
                  } catch (err) {
                    setStatus(
                      err instanceof Error ? err.message : "Failed to reset password.",
                    );
                  } finally {
                    setSubmitting(false);
                  }
                }}
              >
                {({ isSubmitting, status }) => (
                  <Form className="space-y-4" noValidate>
                    {status && (
                      <Alert variant="destructive" data-testid="reset-password-error">
                        <AlertDescription>{status}</AlertDescription>
                      </Alert>
                    )}

                    <FormikField
                      name="newPassword"
                      type="password"
                      label="New Password"
                      placeholder="At least 6 characters"
                    />

                    <FormikField
                      name="confirmPassword"
                      type="password"
                      label="Confirm Password"
                    />

                    <Button type="submit" disabled={isSubmitting} className="w-full">
                      {isSubmitting ? "Resetting..." : "Reset Password"}
                    </Button>
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

export default ResetPassword;
