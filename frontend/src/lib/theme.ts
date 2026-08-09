"use client";

import { useCallback, useEffect, useState } from "react";

export function useTheme() {
  const [mounted, setMounted] = useState(false);
  const [dark, setDark] = useState(false);

  useEffect(() => {
    const stored = localStorage.getItem("theme");
    const isDark = stored === "dark";
    setDark(isDark);
    if (isDark) document.documentElement.classList.add("dark");
    setMounted(true);
  }, []);

  const toggle = useCallback(async (origin?: Element | null) => {
    const swap = () => {
      const next = !dark;
      setDark(next);
      document.documentElement.classList.toggle("dark", next);
      localStorage.setItem("theme", next ? "dark" : "light");
    };

    const canAnimate =
      origin instanceof Element &&
      typeof (document as any).startViewTransition === "function" &&
      !window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    if (!canAnimate) {
      swap();
      return;
    }

    const rect = origin.getBoundingClientRect();
    const x = rect.left + rect.width / 2;
    const y = rect.top + rect.height / 2;
    const maxR = Math.hypot(
      Math.max(x, window.innerWidth - x),
      Math.max(y, window.innerHeight - y)
    );

    const t = (document as any).startViewTransition(swap);
    await t.ready;
    document.documentElement.animate(
      {
        clipPath: [
          `circle(0px at ${x}px ${y}px)`,
          `circle(${maxR}px at ${x}px ${y}px)`,
        ],
      },
      {
        duration: 750,
        easing: "ease-in-out",
        pseudoElement: "::view-transition-new(root)",
      }
    );
  }, [dark]);

  return { dark, mounted, toggle };
}
