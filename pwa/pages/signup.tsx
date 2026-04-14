import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { Formik, Form, Field, ErrorMessage } from "formik";
import { useAuth } from "../contexts/AuthContext";

interface SignUpValues {
  email: string;
  password: string;
  confirmPassword: string;
}

const validate = (values: SignUpValues) => {
  const errors: Partial<SignUpValues> = {};

  if (!values.email) {
    errors.email = "Email is required.";
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
    errors.email = "Invalid email address.";
  }

  if (!values.password) {
    errors.password = "Password is required.";
  } else if (values.password.length < 6) {
    errors.password = "Password must be at least 6 characters.";
  }

  if (!values.confirmPassword) {
    errors.confirmPassword = "Please confirm your password.";
  } else if (values.password !== values.confirmPassword) {
    errors.confirmPassword = "Passwords do not match.";
  }

  return errors;
};

const SignUp = () => {
  const { register } = useAuth();
  const router = useRouter();

  return (
    <>
      <Head>
        <title>Sign Up - Aura</title>
      </Head>
      <div className="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div className="w-full max-w-md bg-white rounded-lg shadow-card p-8">
          <h1 className="text-2xl font-bold text-center text-black mb-6">
            Create an Account
          </h1>

          <Formik<SignUpValues>
            initialValues={{ email: "", password: "", confirmPassword: "" }}
            validate={validate}
            onSubmit={async (values, { setSubmitting, setStatus }) => {
              try {
                await register(values.email, values.password);
                router.push("/signin?registered=true");
              } catch (err) {
                setStatus(
                  err instanceof Error ? err.message : "Registration failed."
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
                    placeholder="At least 6 characters"
                  />
                  <ErrorMessage name="password" component="p" className="mt-1 text-sm text-red-500" />
                </div>

                <div>
                  <label htmlFor="confirmPassword" className="block text-sm font-medium text-gray-700 mb-1">
                    Confirm Password
                  </label>
                  <Field
                    id="confirmPassword"
                    name="confirmPassword"
                    type="password"
                    className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                    placeholder="Re-enter your password"
                  />
                  <ErrorMessage name="confirmPassword" component="p" className="mt-1 text-sm text-red-500" />
                </div>

                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="w-full bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {isSubmitting ? "Creating Account..." : "Sign Up"}
                </button>

                <p className="text-center text-sm text-gray-600">
                  Already have an account?{" "}
                  <Link href="/signin" className="text-cyan-700 font-medium">
                    Sign In
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

export default SignUp;
