import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { Formik, Form } from "formik";
import { useAuth } from "@/contexts/AuthContext";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FormikField } from "@/components/ui/formik-field";

interface SignInValues {
  email: string;
  password: string;
}

const validate = (values: SignInValues) => {
  const errors: Partial<SignInValues> = {};

  if (!values.email) {
    errors.email = "Email is required.";
  }

  if (!values.password) {
    errors.password = "Password is required.";
  }

  return errors;
};

const SignIn = () => {
  const { login } = useAuth();
  const router = useRouter();
  const registered = router.query.registered === "true";
  const reset = router.query.reset === "true";

  return (
    <>
      <Head>
        <title>Sign In - Aura</title>
      </Head>
      <div className="min-h-screen flex items-center justify-center bg-muted px-4 py-8">
        <Card className="w-full max-w-md">
          <CardHeader>
            <CardTitle className="text-2xl text-center">Sign In</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {registered && (
              <Alert variant="success">
                <AlertDescription>
                  Account created successfully. Please sign in.
                </AlertDescription>
              </Alert>
            )}

            {reset && (
              <Alert variant="success" data-testid="password-reset-success">
                <AlertDescription>
                  Password reset successfully. Please sign in with your new password.
                </AlertDescription>
              </Alert>
            )}

            <Formik<SignInValues>
              initialValues={{ email: "", password: "" }}
              validate={validate}
              onSubmit={async (values, { setSubmitting, setStatus }) => {
                try {
                  await login(values.email, values.password);
                  router.push("/account");
                } catch (err) {
                  setStatus(
                    err instanceof Error ? err.message : "Sign in failed.",
                  );
                } finally {
                  setSubmitting(false);
                }
              }}
            >
              {({ isSubmitting, status }) => (
                <Form className="space-y-4" noValidate>
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

                  <FormikField
                    name="password"
                    type="password"
                    label="Password"
                    placeholder="Your password"
                  />

                  <Button type="submit" disabled={isSubmitting} className="w-full">
                    {isSubmitting ? "Signing In..." : "Sign In"}
                  </Button>

                  <p className="text-center text-sm">
                    <Link href="/forgot-password" className="text-cyan-700 font-medium">
                      Forgot password?
                    </Link>
                  </p>

                  <p className="text-center text-sm text-muted-foreground">
                    Don&apos;t have an account?{" "}
                    <Link href="/signup" className="text-cyan-700 font-medium">
                      Sign Up
                    </Link>
                  </p>
                </Form>
              )}
            </Formik>
          </CardContent>
        </Card>
      </div>
    </>
  );
};

export default SignIn;
