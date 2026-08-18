"use client";

import { useCallback, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth-context";

/**
 * Shared logout+redirect for authenticated pages. Sets the `loggingOut` flag
 * BEFORE `logout()` — every page's unauthenticated-redirect effect skips while
 * the flag is set, so a redirect can't pre-empt the destination (which may
 * carry query params like `/auth?notice=password_changed`).
 */
export function useLogoutRedirect() {
  const { logout } = useAuth();
  const router = useRouter();
  const [loggingOut, setLoggingOut] = useState(false);

  const logoutAndRedirect = useCallback(
    async (destination: string) => {
      setLoggingOut(true);
      await logout();
      router.push(destination);
    },
    [logout, router]
  );

  return { loggingOut, logoutAndRedirect };
}