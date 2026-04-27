import { Formik, Form } from "formik";
import { useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";
import { Separator } from "@/components/ui/separator";

interface ChangePasswordValues {
  currentPassword: string;
  newPassword: string;
  confirmPassword: string;
}

const validate = (values: ChangePasswordValues) => {
  const errors: Partial<ChangePasswordValues> = {};

  if (!values.currentPassword) {
    errors.currentPassword = "Current password is required.";
  }

  if (!values.newPassword) {
    errors.newPassword = "New password is required.";
  } else if (values.newPassword.length < 6) {
    errors.newPassword = "New password must be at least 6 characters.";
  }

  if (!values.confirmPassword) {
    errors.confirmPassword = "Please confirm your new password.";
  } else if (values.newPassword !== values.confirmPassword) {
    errors.confirmPassword = "Passwords do not match.";
  }

  return errors;
};

const ChangePasswordForm = () => {
  const { changePassword } = useAuth();
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  return (
    <div className="mt-8">
      <Separator className="mb-6" />
      <h2 className="text-lg font-semibold mb-4">Change Password</h2>

      <Formik<ChangePasswordValues>
        initialValues={{ currentPassword: "", newPassword: "", confirmPassword: "" }}
        validate={validate}
        onSubmit={async (values, { setSubmitting, setStatus, resetForm }) => {
          setSuccessMessage(null);
          try {
            await changePassword(values.currentPassword, values.newPassword);
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
              <Alert variant="success" data-testid="change-password-success">
                <AlertDescription>{successMessage}</AlertDescription>
              </Alert>
            )}

            <FormikField
              name="currentPassword"
              type="password"
              label="Current Password"
            />

            <FormikField
              name="newPassword"
              type="password"
              label="New Password"
              placeholder="At least 6 characters"
            />

            <FormikField
              name="confirmPassword"
              type="password"
              label="Confirm New Password"
            />

            <Button type="submit" disabled={isSubmitting} className="w-full">
              {isSubmitting ? "Updating..." : "Update Password"}
            </Button>
          </Form>
        )}
      </Formik>
    </div>
  );
};

export default ChangePasswordForm;
