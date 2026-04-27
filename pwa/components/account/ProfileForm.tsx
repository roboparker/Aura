import { useState } from "react";
import { Formik, Form, ErrorMessage } from "formik";
import { ENTRYPOINT } from "@/config/entrypoint";
import { useAuth } from "@/contexts/AuthContext";
import { AVATAR_PALETTE } from "@/lib/avatarPalette";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";
import { cn } from "@/lib/utils";

interface Values {
  givenName: string;
  familyName: string;
  nickname: string;
  personalizedColor: string;
}

const validate = (values: Values) => {
  const errors: Partial<Values> = {};
  if (!values.givenName.trim()) errors.givenName = "Given name is required.";
  if (!values.familyName.trim()) errors.familyName = "Family name is required.";
  if (values.givenName.length > 100) errors.givenName = "Too long (max 100).";
  if (values.familyName.length > 100) errors.familyName = "Too long (max 100).";
  if (values.nickname.length > 100) errors.nickname = "Too long (max 100).";
  if (!AVATAR_PALETTE.includes(values.personalizedColor)) {
    errors.personalizedColor = "Pick a color from the palette.";
  }
  return errors;
};

const ProfileForm = () => {
  const { user, refreshUser } = useAuth();
  const [saved, setSaved] = useState(false);

  if (!user) return null;

  const nameIncomplete = !user.givenName.trim() || !user.familyName.trim();

  return (
    <div className="mt-6">
      <h2 className="text-lg font-semibold mb-3">Profile</h2>

      {nameIncomplete && (
        <Alert variant="warning" className="mb-3">
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
          personalizedColor: user.personalizedColor,
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
                personalizedColor: values.personalizedColor,
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
        {({ isSubmitting, status, values, setFieldValue }) => (
          <Form className="space-y-4" noValidate>
            {status && (
              <Alert variant="destructive">
                <AlertDescription>{status}</AlertDescription>
              </Alert>
            )}
            {saved && !status && (
              <Alert variant="success">
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

            <fieldset className="space-y-1.5">
              <legend className="text-sm font-medium leading-none">
                Avatar color{" "}
                <span className="text-muted-foreground font-normal">
                  (used when you have no picture)
                </span>
              </legend>
              <div
                role="radiogroup"
                aria-label="Avatar color"
                className="flex flex-wrap gap-2"
                data-testid="avatar-color-palette"
              >
                {AVATAR_PALETTE.map((color) => {
                  const isSelected = values.personalizedColor === color;
                  return (
                    <button
                      key={color}
                      type="button"
                      role="radio"
                      aria-checked={isSelected}
                      aria-label={color}
                      onClick={() => setFieldValue("personalizedColor", color)}
                      className={cn(
                        "h-8 w-8 rounded-full transition focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ring",
                        isSelected
                          ? "ring-2 ring-offset-2 ring-cyan-700 scale-110"
                          : "hover:scale-105",
                      )}
                      style={{ backgroundColor: color }}
                    />
                  );
                })}
              </div>
              <ErrorMessage
                name="personalizedColor"
                component="p"
                className="text-sm text-destructive"
              />
            </fieldset>

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
