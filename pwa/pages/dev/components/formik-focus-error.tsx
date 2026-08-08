import { Formik, Form } from "formik";

import ComponentDoc from "@/components/dev/ComponentDoc";
import { Button } from "@/components/ui/button";
import { FormikField } from "@/components/ui/formik-field";
import {
  FormikFocusError,
  findFieldControl,
} from "@/components/ui/formik-focus-error";

const errorsFor = (values: { email: string; password: string }) => {
  const errors: Record<string, string> = {};
  if (!values.email) errors.email = "Email is required.";
  if (!values.password) errors.password = "Password is required.";
  return errors;
};

const PlainDemo = () => (
  <Formik
    initialValues={{ email: "", password: "" }}
    validate={errorsFor}
    onSubmit={() => {
      // no-op for the docs preview
    }}
  >
    <Form className="space-y-4" noValidate>
      <FormikFocusError />
      <FormikField required name="email" label="Email" type="email" />
      <FormikField required name="password" label="Password" type="password" />
      <Button type="submit" size="sm">
        Submit empty — focus lands on Email
      </Button>
    </Form>
  </Formik>
);

const SummaryDemo = () => (
  <Formik
    initialValues={{ email: "", password: "" }}
    validate={errorsFor}
    onSubmit={() => {
      // no-op for the docs preview
    }}
  >
    {({ errors, submitCount }) => (
      <Form className="space-y-4" noValidate>
        <FormikFocusError />
        {submitCount > 0 && Object.keys(errors).length > 0 && (
          <div
            data-error-summary=""
            tabIndex={-1}
            role="alert"
            className="rounded-md border border-destructive/50 p-3 text-sm"
          >
            <p className="font-semibold text-destructive">
              Fix the highlighted fields
            </p>
            <ul className="mt-1 space-y-0.5 text-xs">
              {Object.keys(errors).map((key) => (
                <li key={key}>
                  <button
                    type="button"
                    className="underline underline-offset-2"
                    onClick={(e) => {
                      const form = e.currentTarget.closest("form");
                      if (form) findFieldControl(form, key)?.focus();
                    }}
                  >
                    {String(errors[key as keyof typeof errors])}
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}
        <FormikField required name="email" label="Email" type="email" />
        <FormikField required name="password" label="Password" type="password" />
        <Button type="submit" size="sm">
          Submit empty — focus lands on the summary
        </Button>
      </Form>
    )}
  </Formik>
);

const FormikFocusErrorPage = () => (
  <ComponentDoc
    name="FormikFocusError"
    description="Moves focus after a failed submit. Without it, submitting an invalid form leaves focus on <body>: the errors appear but the caret is nowhere near them, so a keyboard user re-tabs the whole form and a screen-reader user gets no announcement at all. Render it once inside a <Form>. It prefers an error summary (any element carrying `data-error-summary`) and otherwise focuses the first invalid control in DOM order — not in `errors` key order, which follows the validator rather than what the user sees. It fires only on a new failed submit and only once the attempt settles, so it can't fight Formik's re-render or steal focus mid-correction."
    importPath={`import { FormikFocusError, findFieldControl, FIELD_NAME_ATTR } from "@/components/ui/formik-focus-error";`}
    examples={[
      {
        title: "No summary — focuses the first invalid field",
        code: `<Form noValidate>
  <FormikFocusError />
  <FormikField required name="email" label="Email" />
  <FormikField required name="password" label="Password" />
  <Button type="submit">Sign in</Button>
</Form>`,
        preview: <PlainDemo />,
      },
      {
        title: "With an error summary — focuses the summary",
        code: `{/* Any element carrying data-error-summary wins over the first
    field: the user hears how many problems there are before being
    dropped into one of them (the GOV.UK error-summary pattern).
    tabIndex={-1} makes it focusable without adding a tab stop. */}
<div data-error-summary tabIndex={-1} role="alert">
  <ul>
    {Object.keys(errors).map((key) => (
      <li key={key}>
        <button
          type="button"
          onClick={(e) => {
            const form = e.currentTarget.closest("form")
            if (form) findFieldControl(form, key)?.focus()
          }}
        >
          {errors[key]}
        </button>
      </li>
    ))}
  </ul>
</div>`,
        preview: <SummaryDemo />,
      },
      {
        title: "Controls that can't be found by name",
        code: `{/* Radix Checkbox/Switch render a visible button plus a HIDDEN
    proxy input that carries the name. Resolving by name alone finds
    the hidden one, so focus lands somewhere invisible — worse than
    not moving it. Mark the visible control instead. */}
<Checkbox {...{ [FIELD_NAME_ATTR]: name }} id={id} />

{/* Then findFieldControl(form, name) returns the visible button, and
    both the summary and FormikFocusError resolve it the same way. */}`,
        preview: (
          <p className="text-sm text-muted-foreground">
            <code>findFieldControl</code> skips anything{" "}
            <code>aria-hidden</code>, <code>tabindex=&quot;-1&quot;</code>, or
            zero-sized, so a hidden proxy input can never be the focus target.
            Use <code>FIELD_NAME_ATTR</code> on design-system controls that
            don&apos;t carry a <code>name</code> of their own — see{" "}
            <code>TermsConsentField</code>.
          </p>
        ),
      },
    ]}
  />
);

export default FormikFocusErrorPage;
