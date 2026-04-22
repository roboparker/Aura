import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { Formik, Form, Field, ErrorMessage } from "formik";
import { useAuth } from "../contexts/AuthContext";

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

  // Wait for router query to hydrate before rendering the token check
  if (!router.isReady) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <p className="text-gray-500">Loading...</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Reset Password - Aura</title>
      </Head>
      <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div className="w-full max-w-md bg-white rounded-lg shadow-card p-8">
          <h1 className="text-2xl font-bold text-center text-black mb-6">
            Reset Password
          </h1>

          {!token ? (
            <div
              className="bg-red-50 text-red-500 p-4 rounded text-sm text-center space-y-3"
              data-testid="reset-password-missing-token"
            >
              <p>This reset link is missing or invalid.</p>
              <p>
                <Link
                  href="/forgot-password"
                  className="text-cyan-700 font-medium"
                >
                  Request a new reset link
                </Link>
              </p>
            </div>
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
                    err instanceof Error ? err.message : "Failed to reset password."
                  );
                } finally {
                  setSubmitting(false);
                }
              }}
            >
              {({ isSubmitting, status }) => (
                <Form className="space-y-4" noValidate>
                  {status && (
                    <div
                      className="bg-red-50 text-red-500 p-3 rounded text-sm"
                      data-testid="reset-password-error"
                    >
                      {status}
                    </div>
                  )}

                  <div>
                    <label
                      htmlFor="newPassword"
                      className="block text-sm font-medium text-gray-700 mb-1"
                    >
                      New Password
                    </label>
                    <Field
                      id="newPassword"
                      name="newPassword"
                      type="password"
                      className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                      placeholder="At least 6 characters"
                    />
                    <ErrorMessage
                      name="newPassword"
                      component="p"
                      className="mt-1 text-sm text-red-500"
                    />
                  </div>

                  <div>
                    <label
                      htmlFor="confirmPassword"
                      className="block text-sm font-medium text-gray-700 mb-1"
                    >
                      Confirm Password
                    </label>
                    <Field
                      id="confirmPassword"
                      name="confirmPassword"
                      type="password"
                      className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                    />
                    <ErrorMessage
                      name="confirmPassword"
                      component="p"
                      className="mt-1 text-sm text-red-500"
                    />
                  </div>

                  <button
                    type="submit"
                    disabled={isSubmitting}
                    className="w-full bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    {isSubmitting ? "Resetting..." : "Reset Password"}
                  </button>
                </Form>
              )}
            </Formik>
          )}
        </div>
      </div>
    </>
  );
};

export default ResetPassword;
