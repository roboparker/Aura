import React, { createContext, useContext, useState, useEffect, useCallback, ReactNode } from "react";
import { ENTRYPOINT } from "../config/entrypoint";

interface User {
  id: number;
  email: string;
  roles: string[];
}

interface AuthContextType {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (email: string, password: string) => Promise<void>;
  logout: () => void;
  error: string | null;
  clearError: () => void;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const clearError = useCallback(() => setError(null), []);

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

  const login = useCallback(async (email: string, password: string) => {
    setError(null);
    const res = await fetch(`${ENTRYPOINT}/auth/login`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ email, password }),
    });

    if (!res.ok) {
      const data = await res.json();
      throw new Error(data.error || "Invalid credentials.");
    }

    const data = await res.json();
    setUser(data);
  }, []);

  const register = useCallback(async (email: string, password: string) => {
    setError(null);
    const res = await fetch(`${ENTRYPOINT}/users`, {
      method: "POST",
      headers: { "Content-Type": "application/ld+json" },
      credentials: "include",
      body: JSON.stringify({ email, plainPassword: password }),
    });

    if (!res.ok) {
      const data = await res.json();
      const message =
        data["hydra:description"] || data.detail || "Registration failed.";
      throw new Error(message);
    }
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
        register,
        logout,
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
