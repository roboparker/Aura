import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { Formik, Form, Field, ErrorMessage } from "formik";
import { useAuth } from "../contexts/AuthContext";

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
      <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div className="w-full max-w-md bg-white rounded-lg shadow-card p-8">
          <h1 className="text-2xl font-bold text-center text-black mb-6">
            Sign In
          </h1>

          {registered && (
            <div className="bg-green-50 text-green-700 p-3 rounded text-sm mb-4">
              Account created successfully. Please sign in.
            </div>
          )}

          {reset && (
            <div
              className="bg-green-50 text-green-700 p-3 rounded text-sm mb-4"
              data-testid="password-reset-success"
            >
              Password reset successfully. Please sign in with your new password.
            </div>
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
                  err instanceof Error ? err.message : "Sign in failed."
                );
              } finally {
                setSubmitting(false);
              }
            }}
          >
            {({ isSubmitting, status }) => (
              <Form className="space-y-4" noValidate>
                {status && (
                  <div className="bg-red-50 text-red-500 p-3 rounded text-sm">
                    {status}
                  </div>
                )}

                <div>
                  <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-1">
                    Email
                  </label>
                  <Field
                    id="email"
                    name="email"
                    type="email"
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                    placeholder="you@example.com"
                  />
                  <ErrorMessage name="email" component="p" className="mt-1 text-sm text-red-500" />
                </div>

                <div>
                  <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-1">
                    Password
                  </label>
                  <Field
                    id="password"
                    name="password"
                    type="password"
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                    placeholder="Your password"
                  />
                  <ErrorMessage name="password" component="p" className="mt-1 text-sm text-red-500" />
                </div>

                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="w-full bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {isSubmitting ? "Signing In..." : "Sign In"}
                </button>

                <p className="text-center text-sm">
                  <Link
                    href="/forgot-password"
                    className="text-cyan-700 font-medium"
                  >
                    Forgot password?
                  </Link>
                </p>

                <p className="text-center text-sm text-gray-600">
                  Don&apos;t have an account?{" "}
                  <Link href="/signup" className="text-cyan-700 font-medium">
                    Sign Up
                  </Link>
                </p>
              </Form>
            )}
          </Formik>
        </div>
      </div>
    </>
  );
};

export default SignIn;
