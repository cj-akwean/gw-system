"use client";

import { useEffect, useState } from "react";
import { remainingMs } from "@/lib/countdown";

export function useCountdown(
  deadline: number | null
): { remaining: number; expired: boolean } {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    if (deadline === null) return;

    const tick = () => setNow(Date.now());
    tick();
    const id = window.setInterval(tick, 1000);
    window.addEventListener("visibilitychange", tick);
    window.addEventListener("focus", tick);

    return () => {
      window.clearInterval(id);
      window.removeEventListener("visibilitychange", tick);
      window.removeEventListener("focus", tick);
    };
  }, [deadline]);

  if (deadline === null) {
    return { remaining: 0, expired: false };
  }

  const remaining = remainingMs(deadline, now);
  return { remaining, expired: remaining <= 0 };
}
