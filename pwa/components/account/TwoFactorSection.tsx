import { useState } from "react";
import { Formik, Form } from "formik";
import { ShieldAlert, ShieldCheck } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
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
import TwoFactorSetupDialog from "./TwoFactorSetupDialog";

const LOW_RECOVERY_THRESHOLD = 3;

const TwoFactorSection = () => {
  const { user, refreshUser } = useAuth();
  const [setupOpen, setSetupOpen] = useState(false);
  const [disableOpen, setDisableOpen] = useState(false);
  const [regenOpen, setRegenOpen] = useState(false);

  if (!user) return null;
  const { enabled, recoveryCodesRemaining } = user.twoFactor;

  return (
    <div className="space-y-3" data-testid="2fa-section">
      <div className="flex items-start gap-3">
        {enabled ? (
          <ShieldCheck className="h-5 w-5 mt-0.5 text-green-600" aria-hidden />
        ) : (
          <ShieldAlert className="h-5 w-5 mt-0.5 text-muted-foreground" aria-hidden />
        )}
        <div className="flex-1">
          <p className="text-sm font-medium">Two-factor authentication</p>
          <p className="text-xs text-muted-foreground">
            {enabled
              ? "Your account is protected with TOTP. You'll be asked for a code at sign-in."
              : "Add a 6-digit code from an authenticator app to every sign-in."}
          </p>
        </div>
        {enabled ? (
          <Button
            variant="outline"
            size="sm"
            onClick={() => setDisableOpen(true)}
            data-testid="2fa-disable-button"
          >
            Disable
          </Button>
        ) : (
          <Button
            size="sm"
            onClick={() => setSetupOpen(true)}
            data-testid="2fa-enable-button"
          >
            Enable
          </Button>
        )}
      </div>

      {enabled && (
        <div className="ml-8 flex items-center justify-between gap-2 text-xs">
          <span
            className={
              recoveryCodesRemaining <= LOW_RECOVERY_THRESHOLD
                ? "text-destructive"
                : "text-muted-foreground"
            }
            data-testid="2fa-recovery-remaining"
          >
            {recoveryCodesRemaining} recovery code{recoveryCodesRemaining === 1 ? "" : "s"}{" "}
            remaining
          </span>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setRegenOpen(true)}
            data-testid="2fa-regenerate-button"
          >
            Regenerate codes
          </Button>
        </div>
      )}

      <TwoFactorSetupDialog
        open={setupOpen}
        onOpenChange={setSetupOpen}
        onEnabled={refreshUser}
      />

      <PasswordConfirmDialog
        open={disableOpen}
        onOpenChange={setDisableOpen}
        title="Disable two-factor authentication"
        description="Confirm with your current password. Disabling 2FA removes your TOTP secret and recovery codes."
        confirmLabel="Disable"
        confirmVariant="destructive"
        endpoint={`${ENTRYPOINT}/me/2fa`}
        method="DELETE"
        onSuccess={refreshUser}
      />

      <RegenerateConfirmDialog
        open={regenOpen}
        onOpenChange={setRegenOpen}
        onSuccess={refreshUser}
      />
    </div>
  );
};

interface PasswordConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: string;
  confirmLabel: string;
  confirmVariant?: "default" | "destructive";
  endpoint: string;
  method: "DELETE" | "POST";
  onSuccess: () => void;
}

const PasswordConfirmDialog = ({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  confirmVariant = "default",
  endpoint,
  method,
  onSuccess,
}: PasswordConfirmDialogProps) => (
  <Dialog open={open} onOpenChange={onOpenChange}>
    <DialogContent className="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>{description}</DialogDescription>
      </DialogHeader>

      <Formik<{ currentPassword: string }>
        initialValues={{ currentPassword: "" }}
        validate={({ currentPassword }) =>
          currentPassword ? {} : { currentPassword: "Password is required." }
        }
        onSubmit={async ({ currentPassword }, { setSubmitting, setStatus, resetForm }) => {
          try {
            const res = await fetch(endpoint, {
              method,
              credentials: "include",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ currentPassword }),
            });
            if (!res.ok) {
              const data = await res.json().catch(() => ({}));
              throw new Error(data.error || "Failed.");
            }
            onSuccess();
            resetForm();
            onOpenChange(false);
          } catch (err) {
            setStatus(err instanceof Error ? err.message : "Failed.");
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
              label="Current password"
              autoComplete="current-password"
              data-testid="2fa-confirm-password"
            />
            <Button
              type="submit"
              variant={confirmVariant}
              disabled={isSubmitting}
              className="w-full"
              data-testid="2fa-confirm-submit"
            >
              {isSubmitting ? "Working..." : confirmLabel}
            </Button>
          </Form>
        )}
      </Formik>
    </DialogContent>
  </Dialog>
);

interface RegenerateProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

const RegenerateConfirmDialog = ({ open, onOpenChange, onSuccess }: RegenerateProps) => {
  const [codes, setCodes] = useState<string[] | null>(null);

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) setCodes(null);
        onOpenChange(next);
      }}
    >
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>
            {codes ? "New recovery codes" : "Regenerate recovery codes"}
          </DialogTitle>
          <DialogDescription>
            {codes
              ? "Save these somewhere safe. Your old codes no longer work."
              : "This invalidates your existing codes. Confirm with your password to continue."}
          </DialogDescription>
        </DialogHeader>

        {codes ? (
          <div className="space-y-3">
            <div
              className="grid grid-cols-2 gap-1.5 bg-muted rounded-md p-3 font-mono text-sm"
              data-testid="2fa-new-recovery-codes"
            >
              {codes.map((c) => (
                <code key={c} className="select-all">
                  {c}
                </code>
              ))}
            </div>
            <Button
              type="button"
              onClick={() => {
                onSuccess();
                onOpenChange(false);
              }}
              className="w-full"
            >
              Done
            </Button>
          </div>
        ) : (
          <Formik<{ currentPassword: string }>
            initialValues={{ currentPassword: "" }}
            validate={({ currentPassword }) =>
              currentPassword ? {} : { currentPassword: "Password is required." }
            }
            onSubmit={async ({ currentPassword }, { setSubmitting, setStatus }) => {
              try {
                const res = await fetch(`${ENTRYPOINT}/me/2fa/recovery-codes`, {
                  method: "POST",
                  credentials: "include",
                  headers: { "Content-Type": "application/json" },
                  body: JSON.stringify({ currentPassword }),
                });
                if (!res.ok) {
                  const data = await res.json().catch(() => ({}));
                  throw new Error(data.error || "Failed.");
                }
                const data = await res.json();
                setCodes(data.recoveryCodes);
              } catch (err) {
                setStatus(err instanceof Error ? err.message : "Failed.");
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
                  label="Current password"
                  autoComplete="current-password"
                />
                <Button type="submit" disabled={isSubmitting} className="w-full">
                  {isSubmitting ? "Working..." : "Regenerate"}
                </Button>
              </Form>
            )}
          </Formik>
        )}
      </DialogContent>
    </Dialog>
  );
};

export default TwoFactorSection;
