import React, { createContext, useContext, useState, useEffect, useCallback, ReactNode } from "react";
import { useTheme } from "next-themes";
import { ENTRYPOINT } from "../config/entrypoint";

export type ThemePreference = "light" | "dark" | "system";
export type NotificationFrequency = "realtime" | "hourly" | "daily";

export interface UserPreferences {
  theme: ThemePreference;
  emailNotificationsEnabled: boolean;
  pushNotificationsEnabled: boolean;
  notificationFrequency: NotificationFrequency;
}

export interface TwoFactorStatus {
  enabled: boolean;
  recoveryCodesRemaining: number;
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
  // Inlined on /api/me so the PWA can apply the saved theme on first paint.
  // Always present — the API merges in defaults for older rows.
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
  changePassword: (currentPassword: string, newPassword: string) => Promise<void>;
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

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const { setTheme } = useTheme();

  const clearError = useCallback(() => setError(null), []);

  // Apply the user's saved theme whenever the profile changes (login, refresh,
  // settings update via updateUserLocally). This makes the theme follow the
  // user across devices instead of being pinned to whatever localStorage on
  // *this* browser remembers from a prior visit.
  useEffect(() => {
    const theme = user?.preferences?.theme;
    if (theme) setTheme(theme);
  }, [user?.preferences?.theme, setTheme]);

  const fetchMe = useCallback(async () => {
    try {
      const res = await fetch(`${ENTRYPOINT}/api/me`, { credentials: "include" });
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
    const res = await fetch(`${ENTRYPOINT}/auth/login`, {
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
    const res = await fetch(`${ENTRYPOINT}/auth/2fa-check`, {
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
    const res = await fetch(`${ENTRYPOINT}/users`, {
      method: "POST",
      headers: { "Content-Type": "application/ld+json" },
      credentials: "include",
      body: JSON.stringify({
        email: input.email,
        plainPassword: input.password,
        givenName: input.givenName,
        familyName: input.familyName,
        ...(input.inviteToken ? { inviteToken: input.inviteToken } : {}),
      }),
    });

    if (!res.ok) {
      const data = await res.json();
      const message =
        data["hydra:description"] || data.detail || "Registration failed.";
      throw new Error(message);
    }
  }, []);

  const changePassword = useCallback(async (currentPassword: string, newPassword: string) => {
    const res = await fetch(`${ENTRYPOINT}/auth/change-password`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ currentPassword, newPassword }),
    });

    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || "Failed to change password.");
    }
  }, []);

  const requestPasswordReset = useCallback(async (email: string) => {
    const res = await fetch(`${ENTRYPOINT}/auth/forgot-password`, {
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
    const res = await fetch(`${ENTRYPOINT}/auth/reset-password`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ token, newPassword }),
    });

    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || "Failed to reset password.");
    }
  }, []);

  const updateUserLocally = useCallback((patch: Partial<User>) => {
    setUser((current) => (current ? { ...current, ...patch } : current));
  }, []);

  const logout = useCallback(() => {
    setUser(null);
    fetch(`${ENTRYPOINT}/auth/logout`, {
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
