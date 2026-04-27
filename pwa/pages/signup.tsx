import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { Formik, Form } from "formik";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FormikField } from "@/components/ui/formik-field";

interface SignUpValues {
  givenName: string;
  familyName: string;
  email: string;
  password: string;
  confirmPassword: string;
}

interface InviteContext {
  email: string;
  groups: { id: string; title: string; invitedBy: string }[];
  expiresAt: string;
}

const validate = (values: SignUpValues) => {
  const errors: Partial<SignUpValues> = {};

  if (!values.givenName.trim()) {
    errors.givenName = "Given name is required.";
  } else if (values.givenName.length > 100) {
    errors.givenName = "Given name must be 100 characters or fewer.";
  }

  if (!values.familyName.trim()) {
    errors.familyName = "Family name is required.";
  } else if (values.familyName.length > 100) {
    errors.familyName = "Family name must be 100 characters or fewer.";
  }

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

  const inviteToken =
    typeof router.query.invite === "string" ? router.query.invite : null;

  const [invite, setInvite] = useState<InviteContext | null>(null);
  const [inviteLoading, setInviteLoading] = useState(false);
  const [inviteError, setInviteError] = useState<string | null>(null);

  useEffect(() => {
    if (!router.isReady) return;
    if (!inviteToken) {
      setInvite(null);
      setInviteError(null);
      return;
    }

    let cancelled = false;
    setInviteLoading(true);
    setInviteError(null);
    (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/invites/${encodeURIComponent(inviteToken)}`,
        );
        if (!res.ok) {
          if (!cancelled) {
            setInvite(null);
            setInviteError(
              "This invitation link is invalid or has expired. You can still sign up below.",
            );
          }
          return;
        }
        const data: InviteContext = await res.json();
        if (!cancelled) setInvite(data);
      } catch {
        if (!cancelled) {
          setInvite(null);
          setInviteError(
            "Couldn't verify your invitation. You can still sign up below.",
          );
        }
      } finally {
        if (!cancelled) setInviteLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [router.isReady, inviteToken]);

  return (
    <>
      <Head>
        <title>Sign Up - Aura</title>
      </Head>
      <div className="min-h-screen flex items-center justify-center bg-muted px-4 py-8">
        <Card className="w-full max-w-md">
          <CardHeader>
            <CardTitle className="text-2xl text-center">Create an Account</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {inviteLoading && (
              <p className="text-sm text-muted-foreground text-center">
                Checking your invitation…
              </p>
            )}

            {inviteError && (
              <Alert variant="warning">
                <AlertDescription>{inviteError}</AlertDescription>
              </Alert>
            )}

            {invite && (
              <Alert variant="info" data-testid="invite-context">
                <AlertDescription>
                  <p className="font-semibold">You&apos;ve been invited to join:</p>
                  <ul className="list-disc list-inside mt-1">
                    {invite.groups.map((group) => (
                      <li key={group.id}>
                        <span className="font-medium">{group.title}</span>{" "}
                        <span className="text-cyan-700/80">
                          (invited by {group.invitedBy})
                        </span>
                      </li>
                    ))}
                  </ul>
                  <p className="mt-2 text-xs">
                    Sign up with <strong>{invite.email}</strong> and we&apos;ll add
                    you automatically.
                  </p>
                </AlertDescription>
              </Alert>
            )}

            <Formik<SignUpValues>
              enableReinitialize
              initialValues={{
                givenName: "",
                familyName: "",
                email: invite?.email ?? "",
                password: "",
                confirmPassword: "",
              }}
              validate={validate}
              onSubmit={async (values, { setSubmitting, setStatus }) => {
                try {
                  await register({
                    email: values.email,
                    password: values.password,
                    givenName: values.givenName.trim(),
                    familyName: values.familyName.trim(),
                    inviteToken: invite ? (inviteToken ?? undefined) : undefined,
                  });
                  router.push("/signin?registered=true");
                } catch (err) {
                  setStatus(
                    err instanceof Error ? err.message : "Registration failed.",
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

                  <div className="grid grid-cols-2 gap-3">
                    <FormikField
                      name="givenName"
                      type="text"
                      autoComplete="given-name"
                      label="Given name"
                    />
                    <FormikField
                      name="familyName"
                      type="text"
                      autoComplete="family-name"
                      label="Family name"
                    />
                  </div>

                  <FormikField
                    name="email"
                    type="email"
                    label="Email"
                    placeholder="you@example.com"
                    readOnly={!!invite}
                    inputClassName={invite ? "bg-muted text-muted-foreground" : undefined}
                    description={
                      invite ? "Locked to the email your invitation was sent to." : undefined
                    }
                  />

                  <FormikField
                    name="password"
                    type="password"
                    label="Password"
                    placeholder="At least 6 characters"
                  />

                  <FormikField
                    name="confirmPassword"
                    type="password"
                    label="Confirm Password"
                    placeholder="Re-enter your password"
                  />

                  <Button type="submit" disabled={isSubmitting} className="w-full">
                    {isSubmitting ? "Creating Account..." : "Sign Up"}
                  </Button>

                  <p className="text-center text-sm text-muted-foreground">
                    Already have an account?{" "}
                    <Link href="/signin" className="text-cyan-700 font-medium">
                      Sign In
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

export default SignUp;
