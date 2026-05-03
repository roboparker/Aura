import { Formik, Form } from "formik";
import { FormikField } from "@/components/ui/formik-field";
import { Button } from "@/components/ui/button";
import ComponentDoc from "@/components/dev/ComponentDoc";

const Demo = () => (
  <Formik
    initialValues={{ email: "", password: "" }}
    validate={(values) => {
      const errors: Record<string, string> = {};
      if (!values.email) errors.email = "Required";
      else if (!/^.+@.+\..+$/.test(values.email)) errors.email = "Invalid email";
      if (values.password && values.password.length < 8) errors.password = "Must be at least 8 characters";
      return errors;
    }}
    onSubmit={() => {
      // no-op for the docs preview
    }}
  >
    <Form className="space-y-4">
      <FormikField name="email" label="Email" type="email" placeholder="you@example.com" />
      <FormikField
        name="password"
        label="Password"
        type="password"
        description="At least 8 characters."
      />
      <Button type="submit">Sign in</Button>
    </Form>
  </Formik>
);

const FormikFieldPage = () => (
  <ComponentDoc
    name="FormikField"
    description="Formik-aware wrapper that ties Label, Input, an optional description, and ErrorMessage together. Default for Formik-backed forms — don't hand-roll <Field> + <label> + <ErrorMessage> blocks."
    importPath={`import { FormikField } from "@/components/ui/formik-field";`}
    examples={[
      {
        title: "Sign-in form",
        code: `<Formik
  initialValues={{ email: "", password: "" }}
  validate={(values) => { /* ... */ }}
  onSubmit={(values) => api.signin(values)}
>
  <Form className="space-y-4">
    <FormikField name="email" label="Email" type="email" placeholder="you@example.com" />
    <FormikField
      name="password"
      label="Password"
      type="password"
      description="At least 8 characters."
    />
    <Button type="submit">Sign in</Button>
  </Form>
</Formik>`,
        preview: <div className="w-full max-w-sm"><Demo /></div>,
      },
    ]}
  />
);

export default FormikFieldPage;
