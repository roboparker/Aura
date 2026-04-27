import { Field, ErrorMessage } from "formik";
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
}

export function FormikField({
  name,
  label,
  description,
  inputClassName,
  containerClassName,
  id,
  ...inputProps
}: FormikFieldProps) {
  const fieldId = id ?? name;
  return (
    <div className={cn("space-y-1.5", containerClassName)}>
      <Label htmlFor={fieldId}>{label}</Label>
      <Field
        as={Input}
        id={fieldId}
        name={name}
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
