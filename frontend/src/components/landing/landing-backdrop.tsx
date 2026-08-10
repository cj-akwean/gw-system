"use client";

import { useTheme } from "@/lib/theme";
import { useLoadingComplete } from "@/lib/loading-context";

export function LandingBackdrop() {
  const { mounted } = useTheme();
  const loadingComplete = useLoadingComplete();

  if (!mounted || !loadingComplete) return null;

  return (
    <div className="pointer-events-none fixed inset-0" aria-hidden="true">
      <div
        className="pointer-events-none fixed inset-0"
        style={{
          backgroundImage:
            "radial-gradient(circle at 1px 1px, var(--dot) 1px, transparent 0)",
          backgroundSize: "20px 20px",
        }}
      />
    </div>
  );
}
