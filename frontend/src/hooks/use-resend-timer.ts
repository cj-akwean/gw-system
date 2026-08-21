"use client";

import { useCallback, useEffect, useState } from "react";

/**
 * Resend countdown for OTP flows. `start()` begins a countdown from `seconds`
 * (default 30); while it runs `canResend` is false; at 0 it flips true again.
 */
export function useResendTimer(seconds = 30): {
  remaining: number;
  canResend: boolean;
  start: () => void;
} {
  const [remaining, setRemaining] = useState(0);

  useEffect(() => {
    if (remaining <= 0) return;

    const id = window.setTimeout(() => setRemaining((n) => n - 1), 1000);
    return () => window.clearTimeout(id);
  }, [remaining]);

  const start = useCallback(() => {
    setRemaining(seconds);
  }, [seconds]);

  return { remaining, canResend: remaining <= 0, start };
}