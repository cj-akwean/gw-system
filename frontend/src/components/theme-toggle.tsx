"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { SunIcon } from "@/components/sun-icon";
import { MoonIcon } from "@/components/moon-icon";

export function ThemeToggle() {
  const [mounted, setMounted] = useState(false);
  const [dark, setDark] = useState(false);
  const ref = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    const stored = localStorage.getItem("theme");
    const isDark = stored === "dark";
    setDark(isDark);
    if (isDark) document.documentElement.classList.add("dark");
    setMounted(true);
  }, []);

  const toggle = useCallback(async () => {
    const btn = ref.current;
    if (!btn) return;

    const rect = btn.getBoundingClientRect();
    const x = rect.left + rect.width / 2;
    const y = rect.top + rect.height / 2;
    const maxR = Math.hypot(
      Math.max(x, window.innerWidth - x),
      Math.max(y, window.innerHeight - y)
    );

    const swap = () => {
      const next = !dark;
      setDark(next);
      document.documentElement.classList.toggle("dark");
      localStorage.setItem("theme", next ? "dark" : "light");
    };

    if (
      !(document as any).startViewTransition ||
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    ) {
      swap();
      return;
    }

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

  if (!mounted) {
    return (
      <button
        ref={ref}
        className="fixed top-6 right-6 z-50 flex h-11 w-11 items-center justify-center rounded-full border backdrop-blur-md transition-all"
        style={{
          borderColor: "var(--toggle-border)",
          background: "var(--toggle-bg)",
        }}
        aria-label="Toggle theme"
      />
    );
  }

  return (
    <button
      ref={ref}
      onClick={toggle}
      className="fixed top-6 right-6 z-50 flex h-11 w-11 cursor-pointer items-center justify-center rounded-full border backdrop-blur-md transition-all hover:scale-105"
      style={{
        borderColor: "var(--toggle-border)",
        background: "var(--toggle-bg)",
      }}
      aria-label="Toggle theme"
    >
      {dark ? (
        <SunIcon size={20} className="text-white" />
      ) : (
        <MoonIcon size={20} className="text-[#333]" />
      )}
    </button>
  );
}
