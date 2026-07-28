import { Field, ErrorMessage, useField } from "formik";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

type InputProps = Omit<React.InputHTMLAttributes<HTMLInputElement>, "name">;

interface FormikFieldProps extends InputProps {
  name: string;
  label: React.ReactNode;
  description?: React.ReactNode;
  inputClassName?: string;
  containerClassName?: string;
  /**
   * Renders to the right of the label on the same row — e.g. a "Forgot
   * password?" link next to the Password label. Omitted = label-only row.
   */
  labelAddon?: React.ReactNode;
}

export function FormikField({
  name,
  label,
  description,
  inputClassName,
  containerClassName,
  labelAddon,
  id,
  ...inputProps
}: FormikFieldProps) {
  const fieldId = id ?? name;
  const [, meta] = useField(name);
  const isInvalid = meta.touched && Boolean(meta.error);

  // The error and description are only useful to a screen reader if the input
  // points at them. Without this the field announces "invalid" with no reason
  // given, and the user has to hunt the form to find out which rule they broke.
  // Only reference ids that are actually rendered.
  const errorId = `${fieldId}-error`;
  const descriptionId = `${fieldId}-description`;
  const describedBy =
    [description ? descriptionId : null, isInvalid ? errorId : null]
      .filter(Boolean)
      .join(" ") || undefined;

  return (
    <div className={cn("space-y-1.5", containerClassName)}>
      {labelAddon ? (
        <div className="flex items-center justify-between">
          <Label htmlFor={fieldId}>{label}</Label>
          {labelAddon}
        </div>
      ) : (
        <Label htmlFor={fieldId}>{label}</Label>
      )}
      <Field
        as={Input}
        id={fieldId}
        name={name}
        aria-invalid={isInvalid || undefined}
        aria-describedby={describedBy}
        className={inputClassName}
        {...inputProps}
      />
      {description && (
        <p id={descriptionId} className="text-xs text-muted-foreground">
          {description}
        </p>
      )}
      <ErrorMessage name={name}>
        {(message) => (
          <p id={errorId} className="text-sm text-destructive">
            {message}
          </p>
        )}
      </ErrorMessage>
    </div>
  );
}
