/**
 * Faithful port of Symfony's
 * `Symfony\Component\Validator\Constraints\PasswordStrengthValidator::estimateStrength()`
 * so the strength meter shown in the signup / password-change forms
 * matches what the backend `Assert\PasswordStrength` constraint
 * computes exactly. Drift between meter and server would surface as
 * "the meter says strong but the server rejected it" or vice versa.
 *
 * The algorithm:
 *   1. Count unique byte values in the password.
 *   2. Sum a pool size from the character classes present (digits, upper,
 *      lower, symbols, control, high-bit) — same constants as the PHP
 *      version, picked to match the size of each class's printable
 *      keyspace.
 *   3. entropy = unique * log2(pool) + (length - unique) * log2(unique)
 *   4. Bucket into 0..4 by entropy threshold.
 *
 * Note on byte semantics: PHP iterates raw bytes; JS strings are
 * UTF-16 code units. For ASCII-only passwords the result is identical;
 * for multi-byte UTF-8 strings the entropy bucket can differ at the
 * margin. ASCII covers the overwhelming majority of real-world
 * passwords, and the backend's `Assert\PasswordStrength` is the
 * authoritative gate either way — the meter is advisory.
 */

export const STRENGTH_VERY_WEAK = 0 as const;
export const STRENGTH_WEAK = 1 as const;
export const STRENGTH_MEDIUM = 2 as const;
export const STRENGTH_STRONG = 3 as const;
export const STRENGTH_VERY_STRONG = 4 as const;

export type PasswordStrengthScore = 0 | 1 | 2 | 3 | 4;

/** Floor enforced by both `User::$plainPassword` and `PasswordController`. */
export const MIN_PASSWORD_LENGTH = 8;
/** Backend rejects below this; meter mirrors. Adjust both in lockstep. */
export const MIN_PASSWORD_STRENGTH: PasswordStrengthScore = STRENGTH_WEAK;

export function estimatePasswordStrength(password: string): PasswordStrengthScore {
  const length = password.length;
  if (length === 0) return STRENGTH_VERY_WEAK;

  // Tally byte counts (UTF-16 code units stand in for PHP's byte view).
  // Object-keyed by code unit rather than a Set so we can iterate the
  // distinct units cheaply for the pool computation below.
  const counts: Record<number, number> = {};
  for (let i = 0; i < length; i++) {
    const code = password.charCodeAt(i);
    counts[code] = (counts[code] ?? 0) + 1;
  }
  const distinctCodes = Object.keys(counts);
  const chars = distinctCodes.length;

  let control = 0;
  let digit = 0;
  let upper = 0;
  let lower = 0;
  let symbol = 0;
  let other = 0;

  for (const codeStr of distinctCodes) {
    const code = Number(codeStr);
    if (code < 32 || code === 127) {
      control = 33;
    } else if (code >= 48 && code <= 57) {
      digit = 10;
    } else if (code >= 65 && code <= 90) {
      upper = 26;
    } else if (code >= 97 && code <= 122) {
      lower = 26;
    } else if (code >= 128) {
      other = 128;
    } else {
      symbol = 33;
    }
  }

  const pool = lower + upper + digit + symbol + control + other;
  // log2(1) is 0; the (length - chars) term gracefully zeroes when every
  // character is distinct, so no special-case needed for chars === 1.
  const entropy =
    chars * Math.log2(pool) + (length - chars) * Math.log2(chars || 1);

  if (entropy >= 120) return STRENGTH_VERY_STRONG;
  if (entropy >= 100) return STRENGTH_STRONG;
  if (entropy >= 80) return STRENGTH_MEDIUM;
  if (entropy >= 60) return STRENGTH_WEAK;
  return STRENGTH_VERY_WEAK;
}

/** Display label for each score; sized so the meter UI can swap copy by score. */
export const STRENGTH_LABELS: Record<PasswordStrengthScore, string> = {
  0: "Very weak",
  1: "Weak",
  2: "Medium",
  3: "Strong",
  4: "Very strong",
};
