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
 * Four-segment meter that visualizes the Symfony PasswordStrength score
 * the backend computes — see `pwa/lib/passwordStrength.ts` for why the
 * meter and the server have to agree. Score 0 lights no bars; 1..4
 * light bars 1..4 left-to-right, so each segment maps cleanly onto one
 * Symfony bucket (WEAK / MEDIUM / STRONG / VERY_STRONG).
 *
 * Below `MIN_PASSWORD_STRENGTH` the lit bars and label render in
 * destructive red; at-or-above the floor they render in the brand
 * teal. Label sits below the bars on the left to keep the meter
 * row a single horizontal element under the password input.
 */
const PasswordStrengthMeter = ({ password }: Props) => {
  const empty = password.length === 0;
  // Skip the entropy calc for the empty case so we don't show "Very
  // weak" while the user hasn't typed anything yet — the field's
  // placeholder is doing the work in that state.
  const score: PasswordStrengthScore = empty ? 0 : estimatePasswordStrength(password);
  const passes = score >= MIN_PASSWORD_STRENGTH;
  const litBars = empty ? 0 : score;

  return (
    <div
      className="space-y-1"
      data-testid="password-strength-meter"
      data-score={score}
      aria-live="polite"
    >
      <div className="flex gap-1">
        {[1, 2, 3, 4].map((idx) => (
          <div
            key={idx}
            className={[
              "h-1 flex-1 rounded-full transition-colors duration-150",
              idx <= litBars
                ? passes
                  ? "bg-primary"
                  : "bg-destructive"
                : "bg-muted",
            ].join(" ")}
          />
        ))}
      </div>
      <p
        className={[
          "text-[10px] font-semibold uppercase tracking-wider tabular-nums min-h-[14px]",
          empty
            ? "text-muted-foreground"
            : passes
              ? "text-primary"
              : "text-destructive",
        ].join(" ")}
      >
        {empty ? "" : STRENGTH_LABELS[score]}
      </p>
    </div>
  );
};

export default PasswordStrengthMeter;
