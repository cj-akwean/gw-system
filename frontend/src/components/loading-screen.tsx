"use client";

import { useEffect, useState } from "react";
import { DotmSquare17 } from "@/components/ui/dotm-square-17";

export function LoadingScreen({ onDone }: { onDone?: () => void }) {
  const [visible, setVisible] = useState(true);
  const [fadeOut, setFadeOut] = useState(false);

  useEffect(() => {
    let cancelled = false;

    const done = () => {
      if (cancelled) return;
      setFadeOut(true);
      setTimeout(() => {
        if (!cancelled) {
          setVisible(false);
          onDone?.();
        }
      }, 450);
    };

    // Fixed splash — no font/network dependency. Waiting on
    // `document.fonts.ready` froze the animation for ~1s while fonts
    // (which are discovered late in dev) finished downloading.
    const timer = setTimeout(done, 650);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [onDone]);

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
        className="animate-pulse text-sm font-semibold tracking-widest uppercase"
        style={{ color: "var(--text)", opacity: 0.6 }}
      >
        Loading
      </p>
    </div>
  );
}
