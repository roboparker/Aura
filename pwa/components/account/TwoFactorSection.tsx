import { useCallback, useEffect, useState } from "react";
import { Formik, Form } from "formik";
import { Eye, EyeOff, ShieldAlert, ShieldCheck } from "lucide-react";
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

/**
 * One slot in the recovery-code grid. `code` is the plaintext captured
 * at consumption time — non-null for spent entries (safe: the hash check
 * rejects them on every challenge), null for still-spendable ones.
 */
interface RecoveryCodeEntry {
  code: string | null;
  consumedAt: string | null;
}

const LOW_RECOVERY_THRESHOLD = 3;

const TwoFactorSection = () => {
  const { user, refreshUser } = useAuth();
  const [setupOpen, setSetupOpen] = useState(false);
  const [disableOpen, setDisableOpen] = useState(false);
  const [regenOpen, setRegenOpen] = useState(false);
  const [codes, setCodes] = useState<RecoveryCodeEntry[] | null>(null);
  const [codesError, setCodesError] = useState<string | null>(null);
  // Step-up reveal: populated by the password-gated reveal dialog,
  // intentionally not persisted anywhere — closing/reopening the panel
  // forces a fresh password challenge so the plaintext can't linger.
  const [revealed, setRevealed] = useState<RecoveryCodeEntry[] | null>(null);
  const [revealOpen, setRevealOpen] = useState(false);

  // Optional-chain through twoFactor: older /auth responses didn't always
  // include the block, and a missing field shouldn't crash the security
  // card while React Query / useAuth refresh in the background.
  const enabled = user?.twoFactor?.enabled ?? false;
  const recoveryCodesRemaining = user?.twoFactor?.recoveryCodesRemaining ?? 0;

  const fetchCodes = useCallback(async () => {
    setCodesError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/me/2fa/recovery-codes`, {
        credentials: "include",
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Could not load recovery codes.");
      }
      const data = (await res.json()) as { recoveryCodes: RecoveryCodeEntry[] };
      setCodes(data.recoveryCodes);
    } catch (err) {
      setCodesError(err instanceof Error ? err.message : "Could not load recovery codes.");
    }
  }, []);

  // Pull the code list whenever 2FA flips on or the remaining-count changes
  // (regenerate, code consumed during a recent challenge). The endpoint is
  // gated on isTotpEnabled server-side, so we skip the fetch otherwise.
  useEffect(() => {
    if (!enabled) {
      setCodes(null);
      setRevealed(null);
      return;
    }
    fetchCodes();
  }, [enabled, recoveryCodesRemaining, fetchCodes]);

  // If the user regenerates while the panel is open, the revealed
  // plaintexts they saw are no longer the same codes that exist on the
  // server — clear so the UI doesn't pretend otherwise.
  useEffect(() => {
    setRevealed(null);
  }, [recoveryCodesRemaining]);

  if (!user) return null;

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
        <div className="ml-8 space-y-2">
          <div className="flex items-center justify-between gap-2 text-xs">
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
            <div className="flex items-center gap-1">
              {revealed ? (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setRevealed(null)}
                  data-testid="2fa-recovery-hide"
                >
                  <EyeOff className="h-3.5 w-3.5" /> Hide codes
                </Button>
              ) : (
                codes &&
                codes.length > 0 && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setRevealOpen(true)}
                    data-testid="2fa-recovery-reveal"
                  >
                    <Eye className="h-3.5 w-3.5" /> Reveal codes
                  </Button>
                )
              )}
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setRegenOpen(true)}
                data-testid="2fa-regenerate-button"
              >
                Regenerate codes
              </Button>
            </div>
          </div>

          {codesError && (
            <Alert variant="destructive">
              <AlertDescription>{codesError}</AlertDescription>
            </Alert>
          )}

          {(revealed ?? codes) && (revealed ?? codes)!.length > 0 && (
            <RecoveryCodeList codes={revealed ?? codes!} />
          )}
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
        onSuccess={() => {
          // recoveryCodesRemaining stays at RECOVERY_CODE_COUNT before and
          // after a regen, so the count-based useEffect won't refetch — we
          // pull the new envelopes explicitly here. Drop any previously
          // revealed plaintext so the UI doesn't keep showing codes that
          // no longer exist.
          refreshUser();
          fetchCodes();
          setRevealed(null);
        }}
      />

      <RevealConfirmDialog
        open={revealOpen}
        onOpenChange={setRevealOpen}
        onRevealed={(entries) => setRevealed(entries)}
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

interface RevealProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Called with the full plaintext list after a successful step-up. */
  onRevealed: (entries: RecoveryCodeEntry[]) => void;
}

/**
 * Password-gated step-up dialog: the cookie session alone isn't enough
 * to reveal unused codes (they're real credentials and a stolen cookie
 * shouldn't yield a portable 2FA bypass), so we re-prompt for the
 * password and hand the response back to the parent for one-time render.
 */
const RevealConfirmDialog = ({ open, onOpenChange, onRevealed }: RevealProps) => (
  <Dialog open={open} onOpenChange={onOpenChange}>
    <DialogContent className="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Reveal recovery codes</DialogTitle>
        <DialogDescription>
          Confirm with your current password to display your recovery codes.
          They'll only stay visible while this page is open.
        </DialogDescription>
      </DialogHeader>

      <Formik<{ currentPassword: string }>
        initialValues={{ currentPassword: "" }}
        validate={({ currentPassword }) =>
          currentPassword ? {} : { currentPassword: "Password is required." }
        }
        onSubmit={async ({ currentPassword }, { setSubmitting, setStatus, resetForm }) => {
          try {
            const res = await fetch(`${ENTRYPOINT}/me/2fa/recovery-codes/reveal`, {
              method: "POST",
              credentials: "include",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ currentPassword }),
            });
            if (!res.ok) {
              const data = await res.json().catch(() => ({}));
              throw new Error(data.error || "Could not reveal codes.");
            }
            const data = (await res.json()) as { recoveryCodes: RecoveryCodeEntry[] };
            onRevealed(data.recoveryCodes);
            resetForm();
            onOpenChange(false);
          } catch (err) {
            setStatus(err instanceof Error ? err.message : "Could not reveal codes.");
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
              data-testid="2fa-reveal-password"
            />
            <Button
              type="submit"
              disabled={isSubmitting}
              className="w-full"
              data-testid="2fa-reveal-submit"
            >
              {isSubmitting ? "Working..." : "Reveal codes"}
            </Button>
          </Form>
        )}
      </Formik>
    </DialogContent>
  </Dialog>
);

interface RecoveryCodeListProps {
  codes: RecoveryCodeEntry[];
}

/**
 * Renders the current recovery-code generation. Still-spendable codes
 * appear as indistinguishable masks (plaintext for *unused* codes is
 * never round-tripped — that would collapse 2FA into 1FA on a password
 * compromise). Consumed codes show their actual plaintext struck-through
 * so the user can cross-reference against the codes they saved at setup.
 * Older consumed entries with no captured plaintext fall back to a
 * struck-through mask.
 */
const RecoveryCodeList = ({ codes }: RecoveryCodeListProps) => {
  return (
    <ul
      className="grid grid-cols-2 gap-1.5 bg-muted rounded-md p-3 font-mono text-sm"
      data-testid="2fa-recovery-list"
    >
      {codes.map((entry, idx) => {
        const consumed = entry.consumedAt !== null;
        const label = entry.code ?? "••••-••••-••••";
        return (
          <li
            key={idx}
            className={[
              "flex items-baseline gap-2",
              consumed && "line-through opacity-50 text-muted-foreground",
            ]
              .filter(Boolean)
              .join(" ")}
            data-testid="2fa-recovery-code"
            data-consumed={consumed ? "true" : "false"}
          >
            <span className="text-xs text-muted-foreground tabular-nums w-6">
              {String(idx + 1).padStart(2, "0")}
            </span>
            <code aria-hidden={entry.code === null}>{label}</code>
            {consumed && (
              <span className="text-[10px] uppercase tracking-wider">used</span>
            )}
          </li>
        );
      })}
    </ul>
  );
};

export default TwoFactorSection;
