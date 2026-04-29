import { useState } from "react";
import { Formik, Form } from "formik";
import { ENTRYPOINT } from "@/config/entrypoint";
import { useAuth } from "@/contexts/AuthContext";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";

interface Values {
  newEmail: string;
}

const validate = (values: Values, currentEmail: string) => {
  const errors: Partial<Values> = {};
  const trimmed = values.newEmail.trim();
  if (!trimmed) {
    errors.newEmail = "New email is required.";
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
    errors.newEmail = "Please enter a valid email address.";
  } else if (trimmed.toLowerCase() === currentEmail.toLowerCase()) {
    errors.newEmail = "That is already your email address.";
  } else if (trimmed.length > 180) {
    errors.newEmail = "Too long (max 180).";
  }
  return errors;
};

const EmailChangeForm = () => {
  const { user } = useAuth();
  const [submitted, setSubmitted] = useState<string | null>(null);

  if (!user) return null;

  return (
    <div className="space-y-3">
      <div>
        <p className="text-sm font-medium text-muted-foreground">Email</p>
        <p>{user.email}</p>
      </div>

      {submitted ? (
        <Alert>
          <AlertDescription>
            We sent a confirmation link to <strong>{submitted}</strong>. Click the
            link in that email to finish the change. Your account email won&rsquo;t
            change until you do.
          </AlertDescription>
        </Alert>
      ) : (
        <Formik<Values>
          initialValues={{ newEmail: "" }}
          validate={(values) => validate(values, user.email)}
          onSubmit={async (values, { setSubmitting, setStatus, resetForm }) => {
            setStatus(null);
            try {
              const res = await fetch(`${ENTRYPOINT}/auth/request-email-change`, {
                method: "POST",
                credentials: "include",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ newEmail: values.newEmail.trim() }),
              });
              if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data.error || "Failed to request email change.");
              }
              setSubmitted(values.newEmail.trim());
              resetForm();
            } catch (err) {
              setStatus(
                err instanceof Error ? err.message : "Failed to request email change.",
              );
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
                name="newEmail"
                type="email"
                autoComplete="email"
                label="New email"
                placeholder="name@example.com"
              />
              <p className="text-xs text-muted-foreground">
                We&rsquo;ll send a confirmation link to the new address. Your
                current email stays in place until you click it.
              </p>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? "Sending..." : "Send confirmation link"}
              </Button>
            </Form>
          )}
        </Formik>
      )}
    </div>
  );
};

export default EmailChangeForm;
