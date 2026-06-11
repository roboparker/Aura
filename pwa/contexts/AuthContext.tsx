import React, { createContext, useContext, useState, useEffect, useCallback, ReactNode } from "react";
import { ENTRYPOINT } from "../config/entrypoint";
import { trackEvent } from "../lib/analytics";
import { fetchWithTimeout } from "../lib/fetchWithTimeout";

export type NotificationFrequency = "realtime" | "hourly" | "daily";

export interface NotificationChannel {
  inApp: boolean;
  email: boolean;
}

export interface EmailDigestPref {
  mode: NotificationFrequency;
  hour: number;
}

export interface QuietHoursPref {
  enabled: boolean;
  start: string;
  end: string;
}

export interface UserPreferences {
  /** IANA time-zone identifier (e.g. "America/New_York"). Defaults to "UTC". */
  timezone: string;
  emailNotificationsEnabled: boolean;
  pushNotificationsEnabled: boolean;
  notificationFrequency: NotificationFrequency;
  /** Per-event delivery matrix: row key → {inApp, email}. */
  notificationMatrix: Record<string, NotificationChannel>;
  emailDigest: EmailDigestPref;
  quietHours: QuietHoursPref;
}

export interface TwoFactorStatus {
  enabled: boolean;
  /** Second-factor method — currently always "totp" when enabled. */
  method?: string;
  recoveryCodesRemaining: number;
  /** Total recovery codes ever issued in the current batch (used + unused). */
  recoveryCodesTotal?: number;
  /** ISO timestamp 2FA was enabled, or null. */
  enabledAt?: string | null;
  /** ISO timestamp of the last successful 2FA challenge, or null. */
  lastVerifiedAt?: string | null;
  /**
   * True when this session signed in with a backup recovery code. Set by
   * the API's TwoFactorRecoveryListener on /auth/2fa-check, cleared once
   * the user either re-enrolls a new authenticator (POST /me/2fa/reenroll
   * + /me/2fa/verify) or disables 2FA (DELETE /me/2fa). The PWA mounts
   * `TwoFactorRecoveryInterstitial` while this is true so the user can't
   * get lost on a session that has no working authenticator.
   *
   * Optional on the wire — older /api/me responses (pre-recovery flow)
   * won't include it; treat missing as false.
   */
  recoveryPending?: boolean;
}

export interface User {
  id: string;
  email: string;
  roles: string[];
  givenName: string;
  familyName: string;
  nickname: string | null;
  personalizedColor: string;
  avatarUrls: { thumb?: string; profile?: string } | null;
  /**
   * True while this account is on the launch waitlist — it can sign in and
   * read /api/me but holds no ROLE_USER server-side, so it's blocked from
   * everything else. The app routes these users to the /waitlist gate
   * (see WaitlistGate in Layout). Cleared in bulk when an admin opens
   * signups. Optional on the wire — treat missing as false.
   */
  waitlisted?: boolean;
  // Inlined on /api/me so the PWA has notification + timezone settings on
  // first paint. Always present — the API merges in defaults for older rows.
  preferences: UserPreferences;
  // Inlined so the security-card render doesn't have to chase a separate
  // /me/2fa/status request — the API merges defaults for legacy rows.
  twoFactor: TwoFactorStatus;
}

/**
 * Result of a `login()` call. When 2FA is required, `requiresTwoFactor` is
 * true and the password step has succeeded — the caller must follow up
 * with `submitTwoFactorCode()` to complete sign-in. The user object isn't
 * populated until the second step succeeds.
 */
export interface LoginResult {
  requiresTwoFactor: boolean;
}

export interface RegisterInput {
  email: string;
  password: string;
  givenName: string;
  familyName: string;
  inviteToken?: string;
  /**
   * Avatar color the user picked at signup. Optional — when omitted,
   * the backend's `UserPasswordHasherProcessor` falls back to a random
   * pick from `AvatarColorService::PALETTE`. Must be a hex from that
   * same palette or the entity-level `Assert\Choice` rejects it.
   */
  personalizedColor?: string;
}

interface AuthContextType {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<LoginResult>;
  /**
   * Submits the second-factor code (TOTP or recovery) after `login()` has
   * returned `requiresTwoFactor: true`. Resolves with the now-authenticated
   * user; throws on a wrong code, leaving the half-authenticated session
   * in place so the user can retry.
   */
  submitTwoFactorCode: (code: string) => Promise<void>;
  register: (input: RegisterInput) => Promise<void>;
  logout: () => void;
  /**
   * Confirms the current owner's identity (TOTP if 2FA is on, password
   * otherwise) and rotates the password. The PWA chooses the right
   * confirmation field based on the user's twoFactor.enabled status.
   */
  changePassword: (
    confirmation: { currentPassword: string } | { totpCode: string },
    newPassword: string,
  ) => Promise<void>;
  requestPasswordReset: (email: string) => Promise<void>;
  resetPassword: (token: string, newPassword: string) => Promise<void>;
  refreshUser: () => Promise<void>;
  // Optimistic, local-only patch of the user object — useful for
  // form previews (e.g. avatar color picker) where we want every
  // mounted UserAvatar to reflect the in-progress choice without
  // round-tripping through the API.
  updateUserLocally: (patch: Partial<User>) => void;
  error: string | null;
  clearError: () => void;
}

/**
 * Thrown by `submitTwoFactorCode` when the backend returns 429. Carries
 * the retry-after window so the challenge form can disable Verify and
 * drive a live countdown.
 */
export class TwoFactorRateLimitError extends Error {
  constructor(message: string, public readonly retryAfterSeconds: number) {
    super(message);
    this.name = "TwoFactorRateLimitError";
  }
}

/**
 * The three reasons `/auth/reset-password` can refuse a token. The PWA
 * uses the discriminator to swap the "this link can't be used" card —
 * expired vs already-used vs unrecognised — so the user gets a clear
 * next step instead of one catch-all message.
 */
export type PasswordResetTokenCode =
  | "token_expired"
  | "token_used"
  | "token_invalid";

export class PasswordResetTokenError extends Error {
  constructor(message: string, public readonly code: PasswordResetTokenCode) {
    super(message);
    this.name = "PasswordResetTokenError";
  }
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const clearError = useCallback(() => setError(null), []);

  const fetchMe = useCallback(async () => {
    try {
      const res = await fetchWithTimeout(`${ENTRYPOINT}/api/me`, { credentials: "include" });
      if (res.ok) {
        const data = await res.json();
        setUser(data);
      } else {
        setUser(null);
      }
    } catch {
      setUser(null);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchMe();
  }, [fetchMe]);

  const login = useCallback(async (email: string, password: string): Promise<LoginResult> => {
    setError(null);
    const res = await fetchWithTimeout(`${ENTRYPOINT}/auth/login`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ email, password }),
    });

    // 401 with `requiresTwoFactor: true` means the password matched but
    // the user has 2FA enabled — keep the half-authenticated session
    // alive (the cookie is set) and let the caller drive the code step.
    if (res.status === 401) {
      const data = await res.json().catch(() => ({}));
      if (data.requiresTwoFactor) {
        return { requiresTwoFactor: true };
      }
      throw new Error(data.error || "Invalid credentials.");
    }

    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || "Invalid credentials.");
    }

    const data = await res.json();
    setUser(data);
    return { requiresTwoFactor: false };
  }, []);

  const submitTwoFactorCode = useCallback(async (code: string) => {
    const res = await fetchWithTimeout(`${ENTRYPOINT}/auth/2fa-check`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ code }),
    });

    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      // 429 from the rate-limit subscriber carries a structured
      // retryAfter (and a Retry-After header) — surface it as a typed
      // error so the form can drive a countdown without parsing the
      // message string.
      if (res.status === 429) {
        throw new TwoFactorRateLimitError(
          data.error || "Too many attempts.",
          typeof data.retryAfter === "number" ? data.retryAfter : 60,
        );
      }
      throw new Error(data.error || "Invalid authentication code.");
    }

    const data = await res.json();
    setUser(data);
  }, []);

  const register = useCallback(async (input: RegisterInput) => {
    setError(null);
    const res = await fetchWithTimeout(`${ENTRYPOINT}/users`, {
      method: "POST",
      headers: { "Content-Type": "application/ld+json" },
      credentials: "include",
      body: JSON.stringify({
        email: input.email,
        plainPassword: input.password,
        givenName: input.givenName,
        familyName: input.familyName,
        ...(input.inviteToken ? { inviteToken: input.inviteToken } : {}),
        ...(input.personalizedColor ? { personalizedColor: input.personalizedColor } : {}),
      }),
    });

    if (!res.ok) {
      const data = await res.json();
      const message =
        data["hydra:description"] || data.detail || "Registration failed.";
      throw new Error(message);
    }

    trackEvent("signup", { invited: Boolean(input.inviteToken) });
  }, []);

  const changePassword = useCallback(async (
    confirmation: { currentPassword: string } | { totpCode: string },
    newPassword: string,
  ) => {
    const res = await fetchWithTimeout(`${ENTRYPOINT}/auth/change-password`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ ...confirmation, newPassword }),
    });

    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || "Failed to change password.");
    }
  }, []);

  const requestPasswordReset = useCallback(async (email: string) => {
    const res = await fetchWithTimeout(`${ENTRYPOINT}/auth/forgot-password`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ email }),
    });

    if (!res.ok) {
      throw new Error("Failed to request password reset.");
    }
  }, []);

  const resetPassword = useCallback(async (token: string, newPassword: string) => {
    const res = await fetchWithTimeout(`${ENTRYPOINT}/auth/reset-password`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ token, newPassword }),
    });

    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      const message = data.error || "Failed to reset password.";
      // The token-rejection branch ships a `code` discriminator so the
      // page can pick the right invalid-link card. Validation errors
      // (length / strength) don't carry a code — fall through to a
      // plain Error and the form will render the inline alert.
      if (
        data.code === "token_expired" ||
        data.code === "token_used" ||
        data.code === "token_invalid"
      ) {
        throw new PasswordResetTokenError(message, data.code);
      }
      throw new Error(message);
    }
  }, []);

  const updateUserLocally = useCallback((patch: Partial<User>) => {
    setUser((current) => (current ? { ...current, ...patch } : current));
  }, []);

  const logout = useCallback(() => {
    setUser(null);
    fetchWithTimeout(`${ENTRYPOINT}/auth/logout`, {
      method: "POST",
      credentials: "include",
    }).catch(() => {});
  }, []);

  return (
    <AuthContext.Provider
      value={{
        user,
        isAuthenticated: !!user,
        isLoading,
        login,
        submitTwoFactorCode,
        register,
        logout,
        changePassword,
        requestPasswordReset,
        resetPassword,
        refreshUser: fetchMe,
        updateUserLocally,
        error,
        clearError,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextType {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error("useAuth must be used within an AuthProvider");
  }
  return context;
}
