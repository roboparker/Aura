import { Field, useField, useFormikContext } from "formik";
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
  const { submitCount } = useFormikContext();

  // "Reward early, punish late." Formik marks a field touched on *blur*, so
  // rendering on `touched` alone tells someone their email is required for the
  // crime of tabbing past it on the way to read the form — the error arrives
  // before they've had a chance to do the thing it's complaining about.
  //
  // An error is shown once either of these is true:
  //   - they've submitted (submitCount > 0), so every problem is fair game; or
  //   - they typed something and left, so there's real input to judge.
  //
  // Blurring a field that's still empty says nothing, because "I haven't
  // filled this in yet" is not a mistake. Clearing a field back to empty
  // before submit returns it to that same not-yet-answered state.
  const value: unknown = meta.value;
  const hasEntry =
    typeof value === "string" ? value.trim() !== "" : Boolean(value);
  const isInvalid =
    Boolean(meta.error) && (submitCount > 0 || (meta.touched && hasEntry));

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
      {/* Not Formik's <ErrorMessage>: it renders on `touched` alone, which is
          the blur-too-early behaviour above. Gate on the same `isInvalid` the
          input's aria-invalid uses, so the visible message and the announced
          state can't disagree. */}
      {isInvalid && typeof meta.error === "string" && (
        <p id={errorId} className="text-sm text-destructive">
          {meta.error}
        </p>
      )}
    </div>
  );
}
