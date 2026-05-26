import {
  MIN_PASSWORD_STRENGTH,
  STRENGTH_LABELS,
  estimatePasswordStrength,
  type PasswordStrengthScore,
} from "@/lib/passwordStrength";

interface Props {
  /** Current password value; an empty string renders an inert meter. */
  password: string;
}

/**
 * Three-bar meter that visualizes the Symfony PasswordStrength score
 * (0..4) the backend computes — see `pwa/lib/passwordStrength.ts` for
 * why it has to match exactly. Each bar fills proportionally to the
 * score; the label and color reflect both the bucket and whether it
 * clears `MIN_PASSWORD_STRENGTH` (the gate the server enforces).
 *
 * Below-floor scores render in destructive red; at-or-above floor in
 * the brand teal. The label text comes from `STRENGTH_LABELS` so the
 * meter stays a thin wrapper over the shared scoring helper.
 */
const PasswordStrengthMeter = ({ password }: Props) => {
  const empty = password.length === 0;
  // Skip the entropy calc for the empty case so we don't show "Very
  // weak" while the user hasn't typed anything yet — the field's
  // placeholder is doing the work in that state.
  const score: PasswordStrengthScore = empty ? 0 : estimatePasswordStrength(password);
  const passes = score >= MIN_PASSWORD_STRENGTH;

  // Three segments because the mockup shows three bars. Map the 0..4
  // score onto how many bars light up: 0 → 0, 1 → 1, 2 → 2, 3-4 → 3.
  const litBars = empty ? 0 : Math.min(3, Math.max(1, score));

  return (
    <div
      className="flex items-center gap-2"
      data-testid="password-strength-meter"
      data-score={score}
      aria-live="polite"
    >
      <div className="flex flex-1 gap-1">
        {[0, 1, 2].map((idx) => (
          <div
            key={idx}
            className={[
              "h-1 flex-1 rounded-full transition-colors duration-150",
              idx < litBars
                ? passes
                  ? "bg-primary"
                  : "bg-destructive"
                : "bg-muted",
            ].join(" ")}
          />
        ))}
      </div>
      <span
        className={[
          "text-[10px] font-semibold uppercase tracking-wider tabular-nums w-20 text-right",
          empty
            ? "text-muted-foreground"
            : passes
              ? "text-primary"
              : "text-destructive",
        ].join(" ")}
      >
        {empty ? "" : STRENGTH_LABELS[score]}
      </span>
    </div>
  );
};

export default PasswordStrengthMeter;
