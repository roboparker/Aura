import Link from "next/link";
import { useRouter } from "next/router";
import { Formik, Form } from "formik";
import { useAuth } from "@/contexts/AuthContext";
import { safeNextPath } from "@/lib/authRedirect";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";

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

      <Formik<SignInValues>
        initialValues={{ email: "", password: "" }}
        validate={validate}
        onSubmit={async (values, { setSubmitting, setStatus }) => {
          try {
            await login(values.email, values.password);
            router.push(safeNextPath(next));
          } catch (err) {
            setStatus(err instanceof Error ? err.message : "Sign in failed.");
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
              autoComplete="email"
            />

            <FormikField
              name="password"
              type="password"
              label="Password"
              placeholder="Your password"
              autoComplete="current-password"
            />

            <Button type="submit" disabled={isSubmitting} className="w-full">
              {isSubmitting ? "Signing In..." : "Sign In"}
            </Button>

            <p className="text-center text-sm">
              <Link href="/forgot-password" className="text-primary font-medium">
                Forgot password?
              </Link>
            </p>
          </Form>
        )}
      </Formik>
    </div>
  );
};

export default SignInForm;
