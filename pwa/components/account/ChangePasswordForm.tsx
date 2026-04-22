import { Formik, Form, Field, ErrorMessage } from "formik";
import { useState } from "react";
import { useAuth } from "../../contexts/AuthContext";

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
    <div className="mt-8 border-t border-gray-200 pt-6">
      <h2 className="text-lg font-semibold text-black mb-4">Change Password</h2>

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
              err instanceof Error ? err.message : "Failed to change password."
            );
          } finally {
            setSubmitting(false);
          }
        }}
      >
        {({ isSubmitting, status }) => (
          <Form className="space-y-4" noValidate>
            {status && (
              <div
                className="bg-red-50 text-red-500 p-3 rounded text-sm"
                data-testid="change-password-error"
              >
                {status}
              </div>
            )}

            {successMessage && (
              <div
                className="bg-green-50 text-green-700 p-3 rounded text-sm"
                data-testid="change-password-success"
              >
                {successMessage}
              </div>
            )}

            <div>
              <label
                htmlFor="currentPassword"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                Current Password
              </label>
              <Field
                id="currentPassword"
                name="currentPassword"
                type="password"
                className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
              />
              <ErrorMessage
                name="currentPassword"
                component="p"
                className="mt-1 text-sm text-red-500"
              />
            </div>

            <div>
              <label
                htmlFor="newPassword"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                New Password
              </label>
              <Field
                id="newPassword"
                name="newPassword"
                type="password"
                className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                placeholder="At least 6 characters"
              />
              <ErrorMessage
                name="newPassword"
                component="p"
                className="mt-1 text-sm text-red-500"
              />
            </div>

            <div>
              <label
                htmlFor="confirmPassword"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                Confirm New Password
              </label>
              <Field
                id="confirmPassword"
                name="confirmPassword"
                type="password"
                className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
              />
              <ErrorMessage
                name="confirmPassword"
                component="p"
                className="mt-1 text-sm text-red-500"
              />
            </div>

            <button
              type="submit"
              disabled={isSubmitting}
              className="w-full bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isSubmitting ? "Updating..." : "Update Password"}
            </button>
          </Form>
        )}
      </Formik>
    </div>
  );
};

export default ChangePasswordForm;
