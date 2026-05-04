import { useRouter } from "next/router";
import { Formik, Form } from "formik";
import { useAuth } from "@/contexts/AuthContext";
import { safeNextPath } from "@/lib/authRedirect";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";

interface Props {
  /** `?next=` from the URL — caller forwards it from the sign-in page. */
  next?: string;
  /**
   * Lets the user back out of the half-authenticated state — they'll need
   * to log in again from the start. The sign-in form re-mounts and the
   * server-side TwoFactorToken is harmless on its own (it can't be used
   * for anything until the right code is submitted).
   */
  onCancel: () => void;
}

interface Values {
  code: string;
}

const validate = ({ code }: Values) => {
  const errors: Partial<Values> = {};
  const trimmed = code.trim();
  if (!trimmed) errors.code = "Enter a code from your authenticator app.";
  return errors;
};

const TwoFactorChallengeForm = ({ next, onCancel }: Props) => {
  const { submitTwoFactorCode } = useAuth();
  const router = useRouter();

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-lg font-semibold">Two-factor verification</h2>
        <p className="text-sm text-muted-foreground">
          Open your authenticator app and enter the 6-digit code, or use one
          of your saved recovery codes.
        </p>
      </div>

      <Formik<Values>
        initialValues={{ code: "" }}
        validate={validate}
        onSubmit={async ({ code }, { setSubmitting, setStatus }) => {
          try {
            await submitTwoFactorCode(code.trim());
            router.push(safeNextPath(next));
          } catch (err) {
            setStatus(err instanceof Error ? err.message : "Verification failed.");
          } finally {
            setSubmitting(false);
          }
        }}
      >
        {({ isSubmitting, status }) => (
          <Form className="space-y-4" noValidate>
            {status && (
              <Alert variant="destructive" data-testid="2fa-error">
                <AlertDescription>{status}</AlertDescription>
              </Alert>
            )}

            <FormikField
              name="code"
              label="Authentication code"
              placeholder="123 456"
              autoComplete="one-time-code"
              inputMode="text"
              autoFocus
              data-testid="2fa-code"
            />

            <Button
              type="submit"
              disabled={isSubmitting}
              className="w-full"
              data-testid="2fa-submit"
            >
              {isSubmitting ? "Verifying..." : "Verify"}
            </Button>

            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={onCancel}
              className="w-full"
            >
              Cancel and start over
            </Button>
          </Form>
        )}
      </Formik>
    </div>
  );
};

export default TwoFactorChallengeForm;
