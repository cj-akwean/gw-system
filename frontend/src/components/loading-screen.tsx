"use client";

import { useEffect, useState } from "react";

// Pure-CSS loading splash. The previous DotmSquare17 matrix was a JS/rAF
// animation — it lagged badly at throttled CPU (4×) because its rAF loop
// competed with hydration, and the whole dotmatrix lib rode in the initial
// bundle just for the splash. CSS keyframes run on the compositor: zero main
// thread, no lag, and the dots pulse even before React hydrates.
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
      }, 350);
    };

    const timer = setTimeout(done, 350);

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
        transition: "opacity 350ms ease",
        opacity: fadeOut ? 0 : 1,
      }}
    >
      <div className="flex items-center gap-2" aria-hidden="true">
        {[0, 1, 2, 3, 4].map((i) => (
          <span
            className="size-2.5 animate-pulse rounded-full"
            key={i}
            style={{
              animationDelay: `${i * 140}ms`,
              background: "var(--primary)",
            }}
          />
        ))}
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
