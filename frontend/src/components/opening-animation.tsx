"use client";

import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import { LoadingScreen } from "@/components/loading-screen";
import { Ripple } from "@/components/canvasui/Ripple";

export function OpeningAnimation({ children }: { children: ReactNode }) {
  const splashRef = useRef<((x: number, y: number, strength?: number) => void) | null>(null);

  // detect desktop for wider ripple radius (default to mobile-safe values during SSR)
  const [isDesktop, setIsDesktop] = useState(false);
  useEffect(() => {
    setIsDesktop(window.innerWidth >= 1024);
  }, []);

  const handleRippleReady = useCallback(
    (ripple: { splash: (x: number, y: number, strength?: number) => void }) => {
      splashRef.current = ripple.splash;
    },
    [],
  );

  const handleLoadingDone = useCallback(() => {
    const splash = splashRef.current;
    if (!splash) return;

    const isDesktop = window.innerWidth >= 1024;
    const isLightMode = !document.documentElement.classList.contains("dark");

    let strength = isDesktop ? 1.8 : 1.5;
    if (isLightMode) strength *= 1.2;

    splash(window.innerWidth / 2, window.innerHeight / 2, strength);
  }, []);

  return (
    <>
      <LoadingScreen onDone={handleLoadingDone} />
      <Ripple
        trigger="none"
        interval={0}
        amplitude={0.6}
        speed={0.7}
        wavelength={isDesktop ? 160 : 90}
        rings={isDesktop ? 4 : 3}
        refraction={120}
        dispersion={0.4}
        shine={0.8}
        onRippleReady={handleRippleReady}
      >
        {children}
      </Ripple>
    </>
  );
}
