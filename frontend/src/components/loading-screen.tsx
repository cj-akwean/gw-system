"use client";

import { useEffect, useState } from "react";
import { DotmSquare17 } from "@/components/ui/dotm-square-17";

export function LoadingScreen({ onDone }: { onDone?: () => void }) {
  const [visible, setVisible] = useState(true);
  const [fadeOut, setFadeOut] = useState(false);

  useEffect(() => {
    let cancelled = false;
    const start = performance.now();

    const done = () => {
      if (cancelled) return;
      const elapsed = performance.now() - start;
      const remaining = Math.max(0, 800 - elapsed);
      setTimeout(() => {
        if (!cancelled) {
          setFadeOut(true);
          setTimeout(() => {
            setVisible(false);
            onDone?.();
          }, 500);
        }
      }, remaining);
    };

    Promise.all([
      document.fonts.ready,
      new Promise<void>((resolve) => {
        if (document.readyState === "complete") resolve();
        else window.addEventListener("load", () => resolve(), { once: true });
      }),
    ]).then(done);

    return () => { cancelled = true; };
  }, []);

  if (!visible) return null;

  return (
    <div
      className="fixed inset-0 z-[9999] flex flex-col items-center justify-center gap-6"
      style={{
        background: "var(--bg)",
        transition: "opacity 500ms ease",
        opacity: fadeOut ? 0 : 1,
      }}
    >
      <div className="flex items-center justify-center">
        <DotmSquare17 size={120} dotSize={6} animated colorPreset="grad-ocean" pattern="full" />
      </div>
      <p
        className="text-sm font-semibold tracking-widest uppercase"
        style={{ color: "var(--text)", opacity: 0.6 }}
      >
        Loading
      </p>
    </div>
  );
}
