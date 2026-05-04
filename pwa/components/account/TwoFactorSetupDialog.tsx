import { useEffect, useState } from "react";
import { Formik, Form } from "formik";
import QRCode from "qrcode";
import { Check, Copy, Download, Loader2, ShieldCheck } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
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

interface SetupStartResponse {
  secret: string;
  provisioningUri: string;
}

interface VerifyResponse {
  enabled: boolean;
  recoveryCodes: string[];
}

type Step = "scan" | "recovery";

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Called once 2FA is fully enabled — caller should refresh `/api/me`. */
  onEnabled: () => void;
}

const TwoFactorSetupDialog = ({ open, onOpenChange, onEnabled }: Props) => {
  const [step, setStep] = useState<Step>("scan");
  const [setupData, setSetupData] = useState<SetupStartResponse | null>(null);
  const [qrDataUri, setQrDataUri] = useState<string | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [bootError, setBootError] = useState<string | null>(null);

  // (Re)bootstrap the setup flow each time the dialog opens. Closing
  // mid-flow throws the in-progress secret away on the server side too —
  // /me/2fa/setup overwrites the previous unconfirmed secret on next open.
  useEffect(() => {
    if (!open) {
      setStep("scan");
      setSetupData(null);
      setQrDataUri(null);
      setRecoveryCodes([]);
      setBootError(null);
      return;
    }

    let cancelled = false;
    (async () => {
      try {
        const res = await fetch(`${ENTRYPOINT}/me/2fa/setup`, {
          method: "POST",
          credentials: "include",
        });
        if (!res.ok) {
          const data = await res.json().catch(() => ({}));
          throw new Error(data.error || "Could not start 2FA setup.");
        }
        const data = (await res.json()) as SetupStartResponse;
        if (cancelled) return;
        setSetupData(data);
        const dataUri = await QRCode.toDataURL(data.provisioningUri, {
          width: 200,
          margin: 1,
        });
        if (cancelled) return;
        setQrDataUri(dataUri);
      } catch (err) {
        if (cancelled) return;
        setBootError(err instanceof Error ? err.message : "Could not start 2FA setup.");
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [open]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md" data-testid="2fa-setup-dialog">
        <DialogHeader>
          <DialogTitle>
            {step === "scan" ? "Set up two-factor authentication" : "Save your recovery codes"}
          </DialogTitle>
          <DialogDescription>
            {step === "scan"
              ? "Scan the QR code with your authenticator app, then enter the 6-digit code it shows."
              : "Each code works once. Save them somewhere safe — they're your only way back in if you lose your authenticator."}
          </DialogDescription>
        </DialogHeader>

        {bootError && (
          <Alert variant="destructive">
            <AlertDescription>{bootError}</AlertDescription>
          </Alert>
        )}

        {step === "scan" && (
          <ScanStep
            qrDataUri={qrDataUri}
            secret={setupData?.secret ?? null}
            onVerified={(codes) => {
              setRecoveryCodes(codes);
              setStep("recovery");
            }}
          />
        )}

        {step === "recovery" && (
          <RecoveryStep
            codes={recoveryCodes}
            onDone={() => {
              onEnabled();
              onOpenChange(false);
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  );
};

interface ScanStepProps {
  qrDataUri: string | null;
  secret: string | null;
  onVerified: (codes: string[]) => void;
}

const ScanStep = ({ qrDataUri, secret, onVerified }: ScanStepProps) => (
  <div className="space-y-4">
    <div className="flex items-center justify-center bg-muted rounded-md p-4">
      {qrDataUri ? (
        // Plain <img> (vs. next/image) because the data URI is generated
        // client-side per render — next/image's optimizer would be a no-op
        // and add render overhead for what's already a 200×200 PNG.
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={qrDataUri}
          alt="2FA QR code"
          width={200}
          height={200}
          data-testid="2fa-qr-code"
        />
      ) : (
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      )}
    </div>

    {secret && (
      <div className="text-center">
        <p className="text-xs text-muted-foreground">Or enter this key manually:</p>
        <code
          className="text-sm font-mono bg-muted rounded px-2 py-1 inline-block mt-1 select-all"
          data-testid="2fa-secret"
        >
          {secret}
        </code>
      </div>
    )}

    <Formik<{ code: string }>
      initialValues={{ code: "" }}
      validate={({ code }) => (code.trim() ? {} : { code: "Enter the code from your app." })}
      onSubmit={async ({ code }, { setSubmitting, setStatus }) => {
        try {
          const res = await fetch(`${ENTRYPOINT}/me/2fa/verify`, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ code: code.trim() }),
          });
          if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.error || "Invalid code.");
          }
          const data = (await res.json()) as VerifyResponse;
          onVerified(data.recoveryCodes);
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
            inputMode="text"
            data-testid="2fa-verify-code"
          />
          <Button type="submit" disabled={isSubmitting || !qrDataUri} className="w-full">
            {isSubmitting ? "Verifying..." : "Verify and enable"}
          </Button>
        </Form>
      )}
    </Formik>
  </div>
);

interface RecoveryStepProps {
  codes: string[];
  onDone: () => void;
}

const RecoveryStep = ({ codes, onDone }: RecoveryStepProps) => {
  const [copied, setCopied] = useState(false);
  const codesText = codes.join("\n");

  const copy = async () => {
    await navigator.clipboard.writeText(codesText);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1500);
  };

  const download = () => {
    const blob = new Blob([codesText], { type: "text/plain" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = "aura-recovery-codes.txt";
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="space-y-4">
      <div
        className="grid grid-cols-2 gap-1.5 bg-muted rounded-md p-3 font-mono text-sm"
        data-testid="2fa-recovery-codes"
      >
        {codes.map((c) => (
          <code key={c} className="select-all">
            {c}
          </code>
        ))}
      </div>

      <div className="flex gap-2">
        <Button type="button" variant="outline" size="sm" onClick={copy} className="flex-1">
          {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
          {copied ? "Copied" : "Copy"}
        </Button>
        <Button type="button" variant="outline" size="sm" onClick={download} className="flex-1">
          <Download className="h-4 w-4" />
          Download
        </Button>
      </div>

      <Alert>
        <ShieldCheck className="h-4 w-4" />
        <AlertDescription>
          Two-factor authentication is now enabled on your account.
        </AlertDescription>
      </Alert>

      <Button type="button" onClick={onDone} className="w-full">
        I've saved them
      </Button>
    </div>
  );
};

export default TwoFactorSetupDialog;
