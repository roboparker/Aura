import Head from "next/head";
import Link from "next/link";
import { useState } from "react";
import { Formik, Form, Field, ErrorMessage } from "formik";
import { useAuth } from "../contexts/AuthContext";

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
      <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div className="w-full max-w-md bg-white rounded-lg shadow-card p-8">
          <h1 className="text-2xl font-bold text-center text-black mb-6">
            Forgot Password
          </h1>

          {submitted ? (
            <div
              className="bg-green-50 text-green-700 p-4 rounded text-sm text-center space-y-3"
              data-testid="forgot-password-success"
            >
              <p>
                If an account exists for that email, a reset link has been
                sent.
              </p>
              <p>
                <Link href="/signin" className="text-cyan-700 font-medium">
                  Back to Sign In
                </Link>
              </p>
            </div>
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
                      : "Failed to request password reset."
                  );
                } finally {
                  setSubmitting(false);
                }
              }}
            >
              {({ isSubmitting, status }) => (
                <Form className="space-y-4" noValidate>
                  <p className="text-sm text-gray-600 mb-2">
                    Enter your email and we&apos;ll send you a link to reset
                    your password.
                  </p>

                  {status && (
                    <div className="bg-red-50 text-red-500 p-3 rounded text-sm">
                      {status}
                    </div>
                  )}

                  <div>
                    <label
                      htmlFor="email"
                      className="block text-sm font-medium text-gray-700 mb-1"
                    >
                      Email
                    </label>
                    <Field
                      id="email"
                      name="email"
                      type="email"
                      className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                      placeholder="you@example.com"
                    />
                    <ErrorMessage
                      name="email"
                      component="p"
                      className="mt-1 text-sm text-red-500"
                    />
                  </div>

                  <button
                    type="submit"
                    disabled={isSubmitting}
                    className="w-full bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    {isSubmitting ? "Sending..." : "Send Reset Link"}
                  </button>

                  <p className="text-center text-sm text-gray-600">
                    <Link href="/signin" className="text-cyan-700 font-medium">
                      Back to Sign In
                    </Link>
                  </p>
                </Form>
              )}
            </Formik>
          )}
        </div>
      </div>
    </>
  );
};

export default ForgotPassword;
