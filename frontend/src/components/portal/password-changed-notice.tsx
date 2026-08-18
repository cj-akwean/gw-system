"use client";

import { useSearchParams } from "next/navigation";
import { AUTH_NOTICE_PASSWORD_CHANGED } from "@/lib/auth-context";

/**
 * "Password updated — sign in again" notice for the /auth page. Reads the
 * query param in its own component so /auth can stay outside a Suspense
 * boundary: static export would otherwise render only the fallback and strip
 * the login form (the LCP element) out of the prerendered HTML.
 */
function PasswordChangedNotice() {
  const searchParams = useSearchParams();

  if (searchParams.get("notice") !== AUTH_NOTICE_PASSWORD_CHANGED) {
    return null;
  }

  return (
    <div
      role="status"
      className="mb-4 flex w-[400px] max-w-full items-center gap-2 rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-primary"
    >
      Password updated. Please sign in with your new password.
    </div>
  );
}

export default PasswordChangedNotice;