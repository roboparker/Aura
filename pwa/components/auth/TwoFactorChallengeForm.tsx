import { useRouter } from "next/router";
import { Formik, Form } from "formik";
import { useAuth } from "@/contexts/AuthContext";
import { safeNextPath } from "@/lib/authRedirect";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";

/**
 * Which credential the user is presenting for the second factor. Both
 * paths hit the same `/auth/2fa-check` endpoint — the server distinguishes
 * by checking the input against the TOTP secret first, then the recovery
 * code hashes — so this is purely UX: input shape, helper copy, validation
 * threshold. The toggle between modes lives in AuthCard's footer.
 */
export type TwoFactorMode = "totp" | "backup";

interface Props {
  /** `?next=` from the URL — caller forwards it from the sign-in page. */
  next?: string;
  /** Whether the form prompts for a TOTP code or a backup recovery code. */
  mode: TwoFactorMode;
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

const COPY: Record<TwoFactorMode, {
  field: { label: string; placeholder: string; autoComplete: string };
  emptyError: string;
}> = {
  totp: {
    field: {
      label: "Authentication code",
      placeholder: "123 456",
      autoComplete: "one-time-code",
    },
    emptyError: "Enter a code from your authenticator app.",
  },
  backup: {
    field: {
      label: "Backup code",
      placeholder: "xxxx-xxxx-xxxx",
      autoComplete: "off",
    },
    emptyError: "Enter one of your saved backup codes.",
  },
};

const TwoFactorChallengeForm = ({ next, mode, onCancel }: Props) => {
  const { submitTwoFactorCode } = useAuth();
  const router = useRouter();
  const copy = COPY[mode];

  return (
    <div className="space-y-4">
      <Formik<Values>
        // Key by mode so switching between TOTP / backup wipes the input
        // (otherwise a half-typed TOTP would linger when the user clicks
        // "Use a backup code instead", and the validation message would
        // be misleading).
        key={mode}
        initialValues={{ code: "" }}
        validate={({ code }) => {
          const errors: Partial<Values> = {};
          if (!code.trim()) errors.code = copy.emptyError;
          return errors;
        }}
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
              label={copy.field.label}
              placeholder={copy.field.placeholder}
              autoComplete={copy.field.autoComplete}
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
