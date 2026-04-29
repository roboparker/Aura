import { useState } from "react";
import { Formik, Form } from "formik";
import { ENTRYPOINT } from "@/config/entrypoint";
import { useAuth } from "@/contexts/AuthContext";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";

interface Values {
  givenName: string;
  familyName: string;
  nickname: string;
}

const validate = (values: Values) => {
  const errors: Partial<Values> = {};
  if (!values.givenName.trim()) errors.givenName = "Given name is required.";
  if (!values.familyName.trim()) errors.familyName = "Family name is required.";
  if (values.givenName.length > 100) errors.givenName = "Too long (max 100).";
  if (values.familyName.length > 100) errors.familyName = "Too long (max 100).";
  if (values.nickname.length > 100) errors.nickname = "Too long (max 100).";
  return errors;
};

const ProfileForm = () => {
  const { user, refreshUser } = useAuth();
  const [saved, setSaved] = useState(false);

  if (!user) return null;

  const nameIncomplete = !user.givenName.trim() || !user.familyName.trim();

  return (
    <div>
      {nameIncomplete && (
        <Alert className="mb-3">
          <AlertDescription>
            Please complete your name so others can recognize you.
          </AlertDescription>
        </Alert>
      )}

      <Formik<Values>
        initialValues={{
          givenName: user.givenName,
          familyName: user.familyName,
          nickname: user.nickname ?? "",
        }}
        validate={validate}
        enableReinitialize
        onSubmit={async (values, { setSubmitting, setStatus }) => {
          setSaved(false);
          try {
            const res = await fetch(`${ENTRYPOINT}/users/${user.id}`, {
              method: "PATCH",
              credentials: "include",
              headers: { "Content-Type": "application/merge-patch+json" },
              body: JSON.stringify({
                givenName: values.givenName.trim(),
                familyName: values.familyName.trim(),
                nickname: values.nickname.trim() || null,
              }),
            });
            if (!res.ok) {
              const data = await res.json().catch(() => ({}));
              throw new Error(data.detail || "Failed to save profile.");
            }
            await refreshUser();
            setSaved(true);
          } catch (err) {
            setStatus(err instanceof Error ? err.message : "Failed to save profile.");
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
            {saved && !status && (
              <Alert>
                <AlertDescription>Profile saved.</AlertDescription>
              </Alert>
            )}

            <div className="grid grid-cols-2 gap-3">
              <FormikField
                name="givenName"
                type="text"
                autoComplete="given-name"
                label="Given name"
              />
              <FormikField
                name="familyName"
                type="text"
                autoComplete="family-name"
                label="Family name"
              />
            </div>

            <FormikField name="nickname" type="text" label="Nickname (optional)" />

            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? "Saving..." : "Save Profile"}
            </Button>
          </Form>
        )}
      </Formik>
    </div>
  );
};

export default ProfileForm;
