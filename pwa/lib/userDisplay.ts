/**
 * Helpers for rendering a user's name consistently across the app.
 *
 * Display rules (in priority order):
 *   1. nickname (trimmed) if set
 *   2. "givenName familyName" (whichever parts exist)
 *   3. email
 *   4. "Unknown"
 *
 * The shape is intentionally loose — any user-like object with the
 * relevant string fields works (the auth-context `User`, project/group
 * `Member`s once they expose names, etc.).
 */
export interface NamedUser {
  nickname?: string | null;
  givenName?: string | null;
  familyName?: string | null;
  email?: string | null;
}

export const displayName = (user: NamedUser): string => {
  const nick = user.nickname?.trim();
  if (nick) return nick;
  const full = [user.givenName, user.familyName]
    .map((part) => part?.trim() ?? "")
    .filter(Boolean)
    .join(" ");
  if (full) return full;
  const email = user.email?.trim();
  if (email) return email;
  return "Unknown";
};

/**
 * Initial(s) for an avatar fallback.
 * - Nickname → first character (e.g. "Bob" → "B")
 * - Given + family → both initials (e.g. "Robo Parker" → "RP")
 * - Otherwise the first character of the email, or "?".
 */
export const initialsFor = (user: NamedUser): string => {
  const nick = user.nickname?.trim();
  if (nick) return nick.charAt(0).toUpperCase();
  const g = user.givenName?.trim().charAt(0) ?? "";
  const f = user.familyName?.trim().charAt(0) ?? "";
  const fromName = (g + f).toUpperCase();
  if (fromName) return fromName;
  const email = user.email?.trim();
  if (email) return email.charAt(0).toUpperCase();
  return "?";
};
