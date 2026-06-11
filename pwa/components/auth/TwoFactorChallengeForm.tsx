import { useEffect, useState } from "react";
import { useRouter } from "next/router";
import { Formik, Form, useFormikContext } from "formik";
import { Clock3, ShieldAlert } from "lucide-react";
import { TwoFactorRateLimitError, useAuth } from "@/contexts/AuthContext";
import {
  markActiveSpaceLanding,
  markActiveSpaceReset,
} from "@/contexts/ActiveSpaceContext";
import { isSafeNextPath, safeNextPath } from "@/lib/authRedirect";
import { landingPathFor, readLanding } from "@/lib/landingDestination";
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
  // Wall-clock instant when the rate-limit bucket refills enough for
  // another attempt — null whenever we're not currently throttled. We
  // store the deadline (vs. seconds-remaining) so the countdown stays
  // accurate even if the browser tab goes inactive and rejoins.
  const [rateLimitedUntil, setRateLimitedUntil] = useState<number | null>(null);
  const rateLimitSecondsLeft = useCountdownTo(rateLimitedUntil);
  // Clear the throttle once the bucket has refilled. Check the
  // wall-clock deadline (not the derived seconds-left) because the
  // countdown's initial state is 0 on the very render where
  // `rateLimitedUntil` was just set — a `seconds <= 0` check would
  // immediately clear the throttle on that render before the countdown
  // effect catches up.
  useEffect(() => {
    if (rateLimitedUntil !== null && Date.now() >= rateLimitedUntil) {
      setRateLimitedUntil(null);
    }
  }, [rateLimitedUntil, rateLimitSecondsLeft]);

  const isRateLimited = rateLimitedUntil !== null;

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
            const user = await submitTwoFactorCode(code.trim());
            // Fresh sign-in complete → resolve the "Start page" preference
            // (#406). A specific-space choice lands there; every other
            // case (incl. a `?next=` deep link) resets to Private (#405).
            const landing = readLanding(user.preferences);
            if (
              !isSafeNextPath(next) &&
              landing.page === "space" &&
              landing.spaceId
            ) {
              markActiveSpaceLanding(user.id, landing.spaceId);
            } else {
              markActiveSpaceReset();
            }
            router.push(safeNextPath(next, landingPathFor(landing)));
          } catch (err) {
            if (err instanceof TwoFactorRateLimitError) {
              setRateLimitedUntil(Date.now() + err.retryAfterSeconds * 1000);
            }
            setStatus(err instanceof Error ? err.message : "Verification failed.");
          } finally {
            setSubmitting(false);
          }
        }}
      >
        {({ isSubmitting, status, values }) => (
          <Form className="space-y-5" noValidate>
            <FormStatusAlert
              status={status}
              mode={mode}
              hasInput={Boolean(values.code.trim())}
              rateLimitSecondsLeft={isRateLimited ? rateLimitSecondsLeft : null}
            />

            {mode === "totp" ? (
              <TotpField hasError={Boolean(status)} disabled={isRateLimited} />
            ) : (
              <FormikField
                name="code"
                label="Backup code"
                placeholder="xxxx-xxxx-xxxx"
                autoComplete="off"
                inputMode="text"
                autoFocus
                inputClassName="font-mono"
                disabled={isRateLimited}
                data-testid="2fa-code"
              />
            )}

            {mode === "totp" && <TotpCountdown />}

            <Button
              type="submit"
              disabled={isSubmitting || isRateLimited}
              className="w-full"
              data-testid="2fa-submit"
            >
              {isRateLimited
                ? `Verify (${rateLimitSecondsLeft}s)`
                : isSubmitting
                ? "Verifying..."
                : "Verify"}
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
const TotpField = ({ hasError, disabled }: { hasError: boolean; disabled: boolean }) => {
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
        disabled={disabled}
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
 * Recomputes the seconds remaining until `deadlineMs` every 250ms.
 * Pure derivation, so flipping the deadline to null pauses the timer.
 * Returns 0 when the deadline has passed (or when deadline is null).
 */
const useCountdownTo = (deadlineMs: number | null): number => {
  const compute = () =>
    deadlineMs === null ? 0 : Math.max(0, Math.ceil((deadlineMs - Date.now()) / 1000));
  const [secondsLeft, setSecondsLeft] = useState(compute);

  useEffect(() => {
    setSecondsLeft(compute());
    if (deadlineMs === null) return;
    const id = window.setInterval(() => {
      setSecondsLeft(Math.max(0, Math.ceil((deadlineMs - Date.now()) / 1000)));
    }, 250);
    return () => window.clearInterval(id);
    // compute is intentionally re-defined per render and not memoized —
    // it closes over deadlineMs which is the only signal that matters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deadlineMs]);

  return secondsLeft;
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
 * a "correct" looking entry didn't match. Rate-limit copy uses the
 * live `rateLimitSecondsLeft` countdown so the user sees the wait
 * tick down without re-querying anything.
 */
const FormStatusAlert = ({
  status,
  mode,
  hasInput,
  rateLimitSecondsLeft,
}: {
  status: string | undefined;
  mode: TwoFactorMode;
  hasInput: boolean;
  /** Seconds until the rate-limit bucket has a token again; null when not throttled. */
  rateLimitSecondsLeft: number | null;
}) => {
  const isRateLimited = rateLimitSecondsLeft !== null;
  if (!status && !isRateLimited) return null;
  // Don't shout "enter a code" right after the user clears the input —
  // they're presumably about to start typing. The button-disabled
  // state already conveys that nothing's submittable yet.
  if (!isRateLimited && !hasInput && status && /enter (the|a|one of)/i.test(status)) {
    return null;
  }

  const title = isRateLimited
    ? "Too many attempts"
    : mode === "totp"
    ? "That code didn't match"
    : "That backup code didn't work";
  const description = isRateLimited
    ? `We allow 5 codes per minute. Try again in ${rateLimitSecondsLeft}s, or use a backup code.`
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
