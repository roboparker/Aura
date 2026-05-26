import { Formik, Form } from "formik";
import { useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";

interface ChangePasswordValues {
  currentPassword: string;
  totpCode: string;
  newPassword: string;
  confirmPassword: string;
}

const ChangePasswordForm = () => {
  const { user, changePassword } = useAuth();
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // When 2FA is on, owning the password alone shouldn't unlock a rotation —
  // a stolen password without the device can't change it; a stolen session
  // without the device can't either. So we swap currentPassword for a
  // fresh TOTP code from the authenticator app.
  const twoFactorEnabled = user?.twoFactor?.enabled ?? false;

  const validate = (values: ChangePasswordValues) => {
    const errors: Partial<ChangePasswordValues> = {};

    if (twoFactorEnabled) {
      if (!values.totpCode) {
        errors.totpCode = "Authentication code is required.";
      }
    } else if (!values.currentPassword) {
      errors.currentPassword = "Current password is required.";
    }

    if (!values.newPassword) {
      errors.newPassword = "New password is required.";
    } else if (values.newPassword.length < 8) {
      errors.newPassword = "New password must be at least 8 characters.";
    }

    if (!values.confirmPassword) {
      errors.confirmPassword = "Please confirm your new password.";
    } else if (values.newPassword !== values.confirmPassword) {
      errors.confirmPassword = "Passwords do not match.";
    }

    return errors;
  };

  return (
    <div>
      <Formik<ChangePasswordValues>
        initialValues={{
          currentPassword: "",
          totpCode: "",
          newPassword: "",
          confirmPassword: "",
        }}
        validate={validate}
        onSubmit={async (values, { setSubmitting, setStatus, resetForm }) => {
          setSuccessMessage(null);
          try {
            const confirmation = twoFactorEnabled
              ? { totpCode: values.totpCode }
              : { currentPassword: values.currentPassword };
            await changePassword(confirmation, values.newPassword);
            setSuccessMessage("Password updated successfully.");
            resetForm();
          } catch (err) {
            setStatus(
              err instanceof Error ? err.message : "Failed to change password.",
            );
          } finally {
            setSubmitting(false);
          }
        }}
      >
        {({ isSubmitting, status }) => (
          <Form className="space-y-4" noValidate>
            {status && (
              <Alert variant="destructive" data-testid="change-password-error">
                <AlertDescription>{status}</AlertDescription>
              </Alert>
            )}

            {successMessage && (
              <Alert data-testid="change-password-success">
                <AlertDescription>{successMessage}</AlertDescription>
              </Alert>
            )}

            {twoFactorEnabled ? (
              <FormikField
                name="totpCode"
                type="text"
                label="Authentication code"
                placeholder="6-digit code from your authenticator app"
                autoComplete="one-time-code"
                inputMode="numeric"
                pattern="[0-9]*"
              />
            ) : (
              <FormikField
                name="currentPassword"
                type="password"
                label="Current Password"
              />
            )}

            <FormikField
              name="newPassword"
              type="password"
              label="New Password"
              placeholder="At least 8 characters"
            />

            <FormikField
              name="confirmPassword"
              type="password"
              label="Confirm New Password"
            />

            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Updating..." : "Update Password"}
            </Button>
          </Form>
        )}
      </Formik>
    </div>
  );
};

export default ChangePasswordForm;
