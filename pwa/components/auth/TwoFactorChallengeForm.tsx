import { useEffect, useState } from "react";
import { useRouter } from "next/router";
import { Formik, Form, useFormikContext } from "formik";
import { Clock3, ShieldAlert } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { safeNextPath } from "@/lib/authRedirect";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSlot,
} from "@/components/ui/input-otp";

/**
 * Which credential the user is presenting for the second factor. Both
 * paths hit the same `/auth/2fa-check` endpoint — the server
 * distinguishes by checking the input against the TOTP secret first,
 * then the recovery code hashes — so this is purely UX.
 */
export type TwoFactorMode = "totp" | "backup";

interface Props {
  /** `?next=` from the URL — caller forwards it from the sign-in page. */
  next?: string;
  /** Whether the form prompts for a TOTP code or a backup recovery code. */
  mode: TwoFactorMode;
  /**
   * Email captured at the password step — surfaced in the "Signing in
   * as X · not you?" line so the user can confirm whose account they're
   * unlocking before they spend a TOTP / backup code on the wrong one.
   */
  email: string | null;
  /**
   * Lets the user back out of the half-authenticated state — they'll
   * need to log in again from the start.
   */
  onCancel: () => void;
}

interface Values {
  code: string;
}

/** RFC 6238 default — codes roll every 30 seconds. */
const TOTP_WINDOW_SECONDS = 30;

const TwoFactorChallengeForm = ({ next, mode, email, onCancel }: Props) => {
  const { submitTwoFactorCode } = useAuth();
  const router = useRouter();

  return (
    <div className="space-y-5">
      <Formik<Values>
        // Key by mode so switching wipes the input; otherwise a half-typed
        // TOTP would linger when the user clicks "Use a backup code
        // instead" and the validation message would be misleading.
        key={mode}
        initialValues={{ code: "" }}
        validate={({ code }) => {
          const errors: Partial<Values> = {};
          if (!code.trim()) {
            errors.code =
              mode === "totp"
                ? "Enter the 6-digit code from your authenticator."
                : "Enter one of your saved backup codes.";
          }
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
        {({ isSubmitting, status, values }) => (
          <Form className="space-y-5" noValidate>
            <FormStatusAlert status={status} mode={mode} hasInput={Boolean(values.code.trim())} />

            {mode === "totp" ? (
              <TotpField hasError={Boolean(status)} />
            ) : (
              <FormikField
                name="code"
                label="Backup code"
                placeholder="xxxx-xxxx-xxxx"
                autoComplete="off"
                inputMode="text"
                autoFocus
                inputClassName="font-mono"
                data-testid="2fa-code"
              />
            )}

            {mode === "totp" && <TotpCountdown />}

            <Button
              type="submit"
              disabled={isSubmitting}
              className="w-full"
              data-testid="2fa-submit"
            >
              {isSubmitting ? "Verifying..." : "Verify"}
            </Button>

            {mode === "backup" && <BackupCodeNote />}

            <p className="text-center text-sm text-muted-foreground">
              {email ? (
                <>
                  Signing in as <span className="text-foreground">{email}</span>
                  <span className="mx-1.5 opacity-50">·</span>
                </>
              ) : null}
              <button
                type="button"
                onClick={onCancel}
                className="text-primary font-semibold hover:text-foreground"
                data-testid="2fa-cancel"
              >
                not you?
              </button>
            </p>
          </Form>
        )}
      </Formik>
    </div>
  );
};

/**
 * Lifts Formik's `setFieldValue` out of render-prop land so the OTP
 * input can shovel digits into the `code` field without us threading
 * the form's bag through every wrapper.
 */
const TotpField = ({ hasError }: { hasError: boolean }) => {
  const { values, setFieldValue, submitForm } = useFormikContext<Values>();

  return (
    <div className="space-y-2">
      <InputOTP
        maxLength={6}
        value={values.code}
        onChange={(value) => {
          setFieldValue("code", value);
          // Auto-submit when the user finishes typing the 6th digit —
          // matches the rhythm of every other TOTP UX (you stop after
          // 6, you don't also reach for the Verify button).
          if (value.length === 6) {
            void submitForm();
          }
        }}
        autoFocus
        data-testid="2fa-code"
        containerClassName="justify-center gap-2"
      >
        <InputOTPGroup className="gap-2">
          {[0, 1, 2, 3, 4, 5].map((idx) => (
            <InputOTPSlot
              key={idx}
              index={idx}
              aria-invalid={hasError ? "true" : "false"}
              className="h-12 w-12 rounded-md border text-xl font-semibold tabular-nums"
            />
          ))}
        </InputOTPGroup>
      </InputOTP>
    </div>
  );
};

/**
 * Live "code refreshes in Ns" indicator. TOTP windows are absolute
 * (`floor(now / 30)`), so seconds-remaining is `30 - (now % 30)`.
 * Re-renders every 250ms so the readout never lags more than a quarter
 * second; cleaning up on unmount avoids the classic "still ticking
 * after the route changed" leak.
 */
const TotpCountdown = () => {
  const [secondsLeft, setSecondsLeft] = useState(() => secondsUntilNextWindow());

  useEffect(() => {
    const tick = () => setSecondsLeft(secondsUntilNextWindow());
    const id = window.setInterval(tick, 250);
    return () => window.clearInterval(id);
  }, []);

  return (
    <p className="flex items-center justify-center gap-1.5 text-xs text-muted-foreground tabular-nums">
      <Clock3 className="h-3 w-3" aria-hidden />
      code refreshes in {secondsLeft}s
    </p>
  );
};

const secondsUntilNextWindow = (): number => {
  const now = Math.floor(Date.now() / 1000);
  return TOTP_WINDOW_SECONDS - (now % TOTP_WINDOW_SECONDS);
};

/**
 * Inline alert for the various error states. Wrong-code copy spells out
 * the 30-second rotation so users who hit a stale code understand why
 * a "correct" looking entry didn't match. Rate-limit copy is wired up
 * for when the backend starts returning 429 on /auth/2fa-check —
 * harmless if it never does.
 */
const FormStatusAlert = ({
  status,
  mode,
  hasInput,
}: {
  status: string | undefined;
  mode: TwoFactorMode;
  hasInput: boolean;
}) => {
  if (!status) return null;
  // Don't shout "enter a code" right after the user clears the input —
  // they're presumably about to start typing. The button-disabled
  // state already conveys that nothing's submittable yet.
  if (!hasInput && /enter (the|a|one of)/i.test(status)) return null;

  const isRateLimited = /too many/i.test(status);
  const title = isRateLimited
    ? "Too many attempts"
    : mode === "totp"
    ? "That code didn't match"
    : "That backup code didn't work";
  const description = isRateLimited
    ? status
    : mode === "totp"
    ? "Enter the current code from your authenticator. Codes rotate every 30s."
    : "Each backup code can only be used once. Try a different one.";

  return (
    <Alert
      variant="destructive"
      data-testid="2fa-error"
      data-error-kind={isRateLimited ? "rate-limit" : "wrong-code"}
    >
      <ShieldAlert className="h-4 w-4" aria-hidden />
      <AlertTitle>{title}</AlertTitle>
      <AlertDescription>{description}</AlertDescription>
    </Alert>
  );
};

/**
 * Small NOTE box explaining that consuming a backup code doesn't burn
 * the others — mirrors the mockup's tooltip-style box. Phrased without
 * the exact remaining-count because we don't know it at challenge time
 * (the count is gated behind full auth).
 */
const BackupCodeNote = () => (
  <div className="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
    <p className="font-semibold uppercase tracking-wider text-foreground">Note</p>
    <p className="mt-1">
      Each backup code works once. The rest stay valid — you can regenerate the
      whole set from Account → Security after you sign in.
    </p>
  </div>
);

export default TwoFactorChallengeForm;
