import { useState } from "react";
import { Formik, Form, Field, ErrorMessage } from "formik";
import { ENTRYPOINT } from "../../config/entrypoint";
import { useAuth } from "../../contexts/AuthContext";
import { AVATAR_PALETTE } from "../../lib/avatarPalette";

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
      <h2 className="text-lg font-semibold text-black mb-3">Profile</h2>

      {nameIncomplete && (
        <div className="bg-amber-50 border border-amber-200 text-amber-800 text-sm p-3 rounded mb-3">
          Please complete your name so others can recognize you.
        </div>
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
          <Form className="space-y-3" noValidate>
            {status && (
              <div className="bg-red-50 text-red-500 p-3 rounded text-sm">{status}</div>
            )}
            {saved && !status && (
              <div className="bg-green-50 text-green-700 p-3 rounded text-sm">Profile saved.</div>
            )}

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label htmlFor="givenName" className="block text-sm font-medium text-gray-700 mb-1">
                  Given name
                </label>
                <Field
                  id="givenName"
                  name="givenName"
                  type="text"
                  autoComplete="given-name"
                  className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                />
                <ErrorMessage name="givenName" component="p" className="mt-1 text-sm text-red-500" />
              </div>
              <div>
                <label htmlFor="familyName" className="block text-sm font-medium text-gray-700 mb-1">
                  Family name
                </label>
                <Field
                  id="familyName"
                  name="familyName"
                  type="text"
                  autoComplete="family-name"
                  className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
                />
                <ErrorMessage name="familyName" component="p" className="mt-1 text-sm text-red-500" />
              </div>
            </div>

            <div>
              <label htmlFor="nickname" className="block text-sm font-medium text-gray-700 mb-1">
                Nickname (optional)
              </label>
              <Field
                id="nickname"
                name="nickname"
                type="text"
                className="w-full rounded-md border-gray-300 shadow-sm focus:border-cyan-500 focus:ring-cyan-500"
              />
              <ErrorMessage name="nickname" component="p" className="mt-1 text-sm text-red-500" />
            </div>

            <fieldset>
              <legend className="block text-sm font-medium text-gray-700 mb-1">
                Avatar color{" "}
                <span className="text-gray-400 font-normal">(used when you have no picture)</span>
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
                      className={`h-8 w-8 rounded-full transition focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-cyan-500 ${
                        isSelected
                          ? "ring-2 ring-offset-2 ring-cyan-700 scale-110"
                          : "hover:scale-105"
                      }`}
                      style={{ backgroundColor: color }}
                    />
                  );
                })}
              </div>
              <ErrorMessage
                name="personalizedColor"
                component="p"
                className="mt-1 text-sm text-red-500"
              />
            </fieldset>

            <button
              type="submit"
              disabled={isSubmitting}
              className="bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isSubmitting ? "Saving..." : "Save Profile"}
            </button>
          </Form>
        )}
      </Formik>
    </div>
  );
};

export default ProfileForm;
