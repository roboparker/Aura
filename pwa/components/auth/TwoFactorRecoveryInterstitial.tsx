import { useEffect, useState } from "react";
import { Formik, Form } from "formik";
// Force the browser bundle: Turbopack doesn't honor qrcode's `browser`
// field swap, so a plain `from "qrcode"` import drags in the Node entry
// which hangs in the browser and leaves the QR spinner forever.
// @ts-expect-error — @types/qrcode only ships types for the package root.
import QRCode from "qrcode/lib/browser";
import { Loader2, RotateCcw, ShieldOff } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { fetchWithTimeout } from "@/lib/fetchWithTimeout";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { FormikField } from "@/components/ui/formik-field";

/**
 * Mounted at app-shell level (Layout.tsx). Becomes a forced modal when
 * the API marks the session `twoFactor.recoveryPending` — i.e. the user
 * just signed in with a backup code. The flow assumes they no longer
 * have a working authenticator, so:
 *
 *   - Re-enroll prompts for the account password (the
 *     SensitiveActionVerifier accepts password during recovery in lieu
 *     of TOTP), rotates the secret via POST /me/2fa/reenroll, walks the
 *     QR-scan + 6-digit confirm just like initial setup, then calls
 *     /me/2fa/verify to flip 2FA back on with fresh recovery codes.
 *   - Disable prompts for the same password, then DELETE /me/2fa
 *     wipes the TOTP secret and recovery codes entirely.
 *
 * The flag is server-side authoritative — clearing only happens once
 * the user completes either path. The dialog deliberately has no close
 * button: navigating around the app with the interstitial dismissed
 * would leave the user in a state where every subsequent page reloads
 * the same prompt, which is more confusing than just keeping the
 * modal up. Logout (via the navbar) is still available behind the
 * overlay; it destroys the session and the flag with it.
 */

type Step = "choose" | "reenroll-password" | "reenroll-scan" | "disable";

interface ReenrollResponse {
  secret: string;
  provisioningUri: string;
}

const TwoFactorRecoveryInterstitial = () => {
  const { user, refreshUser } = useAuth();
  const recoveryPending = user?.twoFactor?.recoveryPending ?? false;
  const [step, setStep] = useState<Step>("choose");
  const [reenrollData, setReenrollData] = useState<ReenrollResponse | null>(null);
  const [qrDataUri, setQrDataUri] = useState<string | null>(null);

  // Reset to the chooser whenever the flag clears (e.g. successful
  // re-enroll/disable, or another tab finished the flow and our /me
  // poll picked it up) so the next time it shows we start fresh.
  useEffect(() => {
    if (!recoveryPending) {
      setStep("choose");
      setReenrollData(null);
      setQrDataUri(null);
    }
  }, [recoveryPending]);

  // Render the QR code whenever new reenroll data arrives.
  useEffect(() => {
    if (!reenrollData) {
      setQrDataUri(null);
      return;
    }
    let cancelled = false;
    (async () => {
      const dataUri = await QRCode.toDataURL(reenrollData.provisioningUri, {
        width: 200,
        margin: 1,
      });
      if (!cancelled) setQrDataUri(dataUri);
    })();
    return () => {
      cancelled = true;
    };
  }, [reenrollData]);

  if (!user || !recoveryPending) return null;

  return (
    <Dialog open onOpenChange={() => undefined}>
      <DialogContent
        className="sm:max-w-md"
        onInteractOutside={(e) => e.preventDefault()}
        onEscapeKeyDown={(e) => e.preventDefault()}
        showCloseButton={false}
        data-testid="2fa-recovery-interstitial"
      >
        {step === "choose" && (
          <ChooseStep
            onPick={(next) => setStep(next)}
          />
        )}

        {step === "reenroll-password" && (
          <ReenrollPasswordStep
            onCancel={() => setStep("choose")}
            onStarted={(data) => {
              setReenrollData(data);
              setStep("reenroll-scan");
            }}
          />
        )}

        {step === "reenroll-scan" && reenrollData && (
          <ReenrollScanStep
            secret={reenrollData.secret}
            qrDataUri={qrDataUri}
            onDone={() => {
              refreshUser();
            }}
          />
        )}

        {step === "disable" && (
          <DisableStep
            onCancel={() => setStep("choose")}
            onDone={() => {
              refreshUser();
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  );
};

interface ChooseStepProps {
  onPick: (step: Step) => void;
}

const ChooseStep = ({ onPick }: ChooseStepProps) => (
  <>
    <DialogHeader>
      <DialogTitle>Recover your account</DialogTitle>
      <DialogDescription>
        You signed in with a recovery code. Your authenticator no longer matches
        this account — pick how you'd like to fix it.
      </DialogDescription>
    </DialogHeader>

    <div className="space-y-2">
      <Button
        type="button"
        onClick={() => onPick("reenroll-password")}
        className="w-full justify-start gap-3 h-auto py-3 whitespace-normal"
        data-testid="2fa-recovery-reenroll"
      >
        <RotateCcw className="h-4 w-4 shrink-0" />
        <div className="text-left min-w-0">
          <div className="font-medium">Set up a new authenticator</div>
          <div className="text-xs opacity-80">
            Recommended. Scan a fresh QR code and keep two-factor on.
          </div>
        </div>
      </Button>
      <Button
        type="button"
        variant="outline"
        onClick={() => onPick("disable")}
        className="w-full justify-start gap-3 h-auto py-3 whitespace-normal"
        data-testid="2fa-recovery-disable"
      >
        <ShieldOff className="h-4 w-4 shrink-0" />
        <div className="text-left min-w-0">
          <div className="font-medium">Turn off two-factor authentication</div>
          <div className="text-xs text-muted-foreground">
            Less secure. You can turn it back on any time from your account
            settings.
          </div>
        </div>
      </Button>
    </div>
  </>
);

interface ReenrollPasswordStepProps {
  onCancel: () => void;
  onStarted: (data: ReenrollResponse) => void;
}

const ReenrollPasswordStep = ({ onCancel, onStarted }: ReenrollPasswordStepProps) => (
  <>
    <DialogHeader>
      <DialogTitle>Confirm it's you</DialogTitle>
      <DialogDescription>
        Enter your account password to rotate your two-factor secret.
      </DialogDescription>
    </DialogHeader>

    <Formik<{ currentPassword: string }>
      initialValues={{ currentPassword: "" }}
      validate={({ currentPassword }) =>
        currentPassword ? {} : { currentPassword: "Password is required." }
      }
      onSubmit={async ({ currentPassword }, { setSubmitting, setStatus }) => {
        try {
          const res = await fetchWithTimeout(`${ENTRYPOINT}/me/2fa/reenroll`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ currentPassword }),
          });
          if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.error || "Could not start re-enrollment.");
          }
          const data = (await res.json()) as ReenrollResponse;
          onStarted(data);
        } catch (err) {
          setStatus(err instanceof Error ? err.message : "Could not start re-enrollment.");
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
            name="currentPassword"
            type="password"
            label="Password"
            autoComplete="current-password"
            data-testid="2fa-recovery-password"
          />
          <div className="flex gap-2">
            <Button type="button" variant="ghost" onClick={onCancel} className="flex-1">
              Back
            </Button>
            <Button
              type="submit"
              disabled={isSubmitting}
              className="flex-1"
              data-testid="2fa-recovery-password-submit"
            >
              {isSubmitting ? "Working..." : "Continue"}
            </Button>
          </div>
        </Form>
      )}
    </Formik>
  </>
);

interface ReenrollScanStepProps {
  secret: string;
  qrDataUri: string | null;
  onDone: () => void;
}

const ReenrollScanStep = ({ secret, qrDataUri, onDone }: ReenrollScanStepProps) => (
  <>
    <DialogHeader>
      <DialogTitle>Scan the new QR code</DialogTitle>
      <DialogDescription>
        Add Aura to your authenticator app, then enter the 6-digit code it
        shows to finish re-enrollment.
      </DialogDescription>
    </DialogHeader>

    <div className="space-y-4">
      <div className="flex items-center justify-center bg-muted rounded-md p-4">
        {qrDataUri ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={qrDataUri}
            alt="New 2FA QR code"
            width={200}
            height={200}
            data-testid="2fa-recovery-qr"
          />
        ) : (
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        )}
      </div>

      <div className="text-center">
        <p className="text-xs text-muted-foreground">Or enter this key manually:</p>
        <code
          className="text-sm font-mono bg-muted rounded px-2 py-1 inline-block mt-1 select-all"
          data-testid="2fa-recovery-secret"
        >
          {secret}
        </code>
      </div>

      <Formik<{ code: string }>
        initialValues={{ code: "" }}
        validate={({ code }) => (code.trim() ? {} : { code: "Enter the code from your app." })}
        onSubmit={async ({ code }, { setSubmitting, setStatus }) => {
          try {
            const res = await fetchWithTimeout(`${ENTRYPOINT}/me/2fa/verify`, {
              method: "POST",
              credentials: "include",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ code: code.trim() }),
            });
            if (!res.ok) {
              const data = await res.json().catch(() => ({}));
              throw new Error(data.error || "Invalid code.");
            }
            onDone();
          } catch (err) {
            setStatus(err instanceof Error ? err.message : "Invalid code.");
          } finally {
            setSubmitting(false);
          }
        }}
      >
        {({ isSubmitting, status }) => (
          <Form className="space-y-3" noValidate>
            {status && (
              <Alert variant="destructive">
                <AlertDescription>{status}</AlertDescription>
              </Alert>
            )}
            <FormikField
              name="code"
              label="Verification code"
              placeholder="123 456"
              autoComplete="one-time-code"
              inputMode="numeric"
              pattern="[0-9]*"
              data-testid="2fa-recovery-verify-code"
            />
            <Button
              type="submit"
              disabled={isSubmitting || !qrDataUri}
              className="w-full"
              data-testid="2fa-recovery-verify-submit"
            >
              {isSubmitting ? "Verifying..." : "Finish re-enrollment"}
            </Button>
          </Form>
        )}
      </Formik>
    </div>
  </>
);

interface DisableStepProps {
  onCancel: () => void;
  onDone: () => void;
}

const DisableStep = ({ onCancel, onDone }: DisableStepProps) => (
  <>
    <DialogHeader>
      <DialogTitle>Turn off two-factor authentication</DialogTitle>
      <DialogDescription>
        Enter your account password. Your TOTP secret and remaining recovery
        codes will be deleted.
      </DialogDescription>
    </DialogHeader>

    <Formik<{ currentPassword: string }>
      initialValues={{ currentPassword: "" }}
      validate={({ currentPassword }) =>
        currentPassword ? {} : { currentPassword: "Password is required." }
      }
      onSubmit={async ({ currentPassword }, { setSubmitting, setStatus }) => {
        try {
          const res = await fetchWithTimeout(`${ENTRYPOINT}/me/2fa`, {
            method: "DELETE",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ currentPassword }),
          });
          if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.error || "Could not disable 2FA.");
          }
          onDone();
        } catch (err) {
          setStatus(err instanceof Error ? err.message : "Could not disable 2FA.");
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
            name="currentPassword"
            type="password"
            label="Password"
            autoComplete="current-password"
            data-testid="2fa-recovery-disable-password"
          />
          <div className="flex gap-2">
            <Button type="button" variant="ghost" onClick={onCancel} className="flex-1">
              Back
            </Button>
            <Button
              type="submit"
              variant="destructive"
              disabled={isSubmitting}
              className="flex-1"
              data-testid="2fa-recovery-disable-submit"
            >
              {isSubmitting ? "Working..." : "Disable 2FA"}
            </Button>
          </div>
        </Form>
      )}
    </Formik>
  </>
);

export default TwoFactorRecoveryInterstitial;
