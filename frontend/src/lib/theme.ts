"use client";

import { useCallback, useSyncExternalStore } from "react";

// Module-level theme store. `useTheme` was previously per-instance useState —
// toggling updated only the toggling component, so canvas consumers
// (fractal grid blend, ocean background) kept stale colors until remount.
let dark = true;
const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

function getSnapshot(): boolean {
  return dark;
}

function applyTheme(next: boolean) {
  dark = next;
  document.documentElement.classList.toggle("dark", next);
  try {
    localStorage.setItem("theme", next ? "dark" : "light");
  } catch {
    // ignore — storage may be unavailable (private mode)
  }
  listeners.forEach((listener) => listener());
}

// Initialize from storage (guarded: only once, first client use).
let initialized = false;
function ensureInitialized() {
  if (initialized) return;
  initialized = true;
  try {
    if (localStorage.getItem("theme") === "dark") {
      dark = true;
    }
  } catch {
    // ignore — storage may be unavailable
  }
}

export function useTheme() {
  ensureInitialized();
  const isDark = useSyncExternalStore(subscribe, getSnapshot, () => true);

  const toggle = useCallback(
    async (origin?: Element | null) => {
      const swap = () => {
        applyTheme(!isDark);
      };

      // The clip-path circle reveal animates the whole page — at throttled
      // mobile CPUs it stretches for seconds. Skip it on mobile (instant swap);
      // desktop keeps the animation.
      const isMobile = window.matchMedia("(max-width: 767px)").matches;
      const canAnimate =
        origin instanceof Element &&
        typeof document.startViewTransition === "function" &&
        !window.matchMedia("(prefers-reduced-motion: reduce)").matches &&
        !isMobile;

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

      const t = document.startViewTransition(swap);
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
    },
    [isDark]
  );

  // SSR/theme-consistency: `dark` always reflects the store; React's
  // useSyncExternalStore re-renders synchronously after hydration when the
  // client snapshot differs, so no light-flash on dark-theme users.
  return { dark: isDark, mounted: initialized, toggle };
}
