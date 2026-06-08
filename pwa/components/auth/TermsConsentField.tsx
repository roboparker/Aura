import Link from "next/link";
import { useField } from "formik";
import { Checkbox } from "@/components/ui/checkbox";
import { cn } from "@/lib/utils";

/**
 * Affirmative "I agree to the Terms and Privacy Policy" checkbox used on
 * every account-creation surface (standard signup, waitlist signup, and
 * invite acceptance). This is the clickwrap gate - the parent form’s
 * `validate` must treat the bound boolean as required so submission is
 * blocked until the box is checked.
 *
 * Formik-aware via `useField`; the Radix Checkbox is not a native input,
 * so we drive its value through `setValue`/`setTouched` by hand.
 */
const TermsConsentField = ({ name = "acceptTerms" }: { name?: string }) => {
  const [field, meta, helpers] = useField<boolean>(name);
  const showError = Boolean(meta.error) && meta.touched;
  const id = `consent-${name}`;

  return (
    <div className="space-y-1.5">
      <div className="flex items-start gap-2.5">
        <Checkbox
          id={id}
          checked={field.value}
          onCheckedChange={(checked) => {
            helpers.setValue(checked === true);
            helpers.setTouched(true);
          }}
          onBlur={() => helpers.setTouched(true)}
          aria-invalid={showError}
          aria-describedby={showError ? `${id}-error` : undefined}
          className="mt-0.5"
        />
        <label
          htmlFor={id}
          className={cn(
            "text-xs leading-relaxed text-muted-foreground select-none cursor-pointer",
            showError && "text-destructive",
          )}
        >
          I agree to Aura&apos;s{" "}
          <Link
            href="/terms"
            target="_blank"
            className="text-primary font-semibold hover:text-foreground"
          >
            Terms
          </Link>{" "}
          and{" "}
          <Link
            href="/privacy"
            target="_blank"
            className="text-primary font-semibold hover:text-foreground"
          >
            Privacy Policy
          </Link>
          .
        </label>
      </div>
      {showError && (
        <p id={`${id}-error`} className="text-xs text-destructive pl-[26px]">
          {meta.error}
        </p>
      )}
    </div>
  );
};

export default TermsConsentField;
