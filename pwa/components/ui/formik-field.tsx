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
        className={inputClassName}
        {...inputProps}
      />
      {description && (
        <p className="text-xs text-muted-foreground">{description}</p>
      )}
      <ErrorMessage name={name} component="p" className="text-sm text-destructive" />
    </div>
  );
}
