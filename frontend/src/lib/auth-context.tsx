"use client";

import {
  createContext,
  useContext,
  useState,
  useEffect,
  useCallback,
  useMemo,
  type ReactNode,
} from "react";
import { googleLoginApi, loginApi, logoutApi, registerApi, updateProfileApi, type PortalUser } from "./api";

export const AUTH_NOTICE_PASSWORD_CHANGED = "password_changed";

interface AuthContextValue {
  user: PortalUser | null;
  token: string | null;
  isAuthenticated: boolean;
  ready: boolean;
  login: (email: string, password: string) => Promise<void>;
  signup: (email: string, password: string) => Promise<void>;
  loginWithGoogle: (credential: string) => Promise<void>;
  updateProfile: (name: string, avatarId: number, phone?: string | null) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<PortalUser | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    const stored = localStorage.getItem("auth");
    if (stored) {
      try {
        const parsed = JSON.parse(stored);
        setToken(parsed.token);
        setUser(parsed.user);
      } catch {
        localStorage.removeItem("auth");
      }
    }
    setReady(true);
  }, []);

  const applySession = useCallback((data: { token: string; user: PortalUser }) => {
    const payload = { token: data.token, user: data.user };
    localStorage.setItem("auth", JSON.stringify(payload));
    setToken(data.token);
    setUser(data.user);
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    applySession(await loginApi(email, password));
  }, [applySession]);

  const signup = useCallback(async (email: string, password: string) => {
    applySession(await registerApi(email, password));
  }, [applySession]);

  const loginWithGoogle = useCallback(async (credential: string) => {
    applySession(await googleLoginApi(credential));
  }, [applySession]);

  const updateProfile = useCallback(
    async (name: string, avatarId: number, phone?: string | null) => {
      const updated = await updateProfileApi(name, avatarId, phone);
      setUser((prev) => (prev ? { ...prev, ...updated } : prev));
      try {
        const stored = JSON.parse(localStorage.getItem("auth") ?? "{}");
        stored.user = { ...stored.user, ...updated };
        localStorage.setItem("auth", JSON.stringify(stored));
      } catch {
        // ignore — the in-memory state is authoritative for this session
      }
    },
    []
  );

  const logout = useCallback(async () => {
    await logoutApi().catch(() => {});
    localStorage.removeItem("auth");
    setToken(null);
    setUser(null);
  }, []);

  const value = useMemo(
    () => ({ user, token, isAuthenticated: !!token, ready, login, signup, loginWithGoogle, updateProfile, logout }),
    [user, token, ready, login, signup, loginWithGoogle, updateProfile, logout],
  );

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
