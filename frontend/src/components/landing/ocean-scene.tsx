"use client";

import { useEffect, useState } from "react";
import { LiquidOcean } from "@/components/ui/liquid-ocean";
import { useTheme } from "@/lib/theme";

// The three.js ocean scene — kept in its own module so the dynamic import in
// ocean-background.tsx can split it out of the initial bundle entirely.
export function OceanScene() {
  const { dark } = useTheme();
  const [narrow, setNarrow] = useState(false);

  useEffect(() => {
    if (typeof window.matchMedia !== "function") return;
    const mq = window.matchMedia("(max-width: 767px)");
    const apply = () => setNarrow(mq.matches);
    apply();
    mq.addEventListener("change", apply);
    return () => mq.removeEventListener("change", apply);
  }, []);

  return (
    <LiquidOcean
      accentColor={0x7dd3fc}
      backgroundColor={dark ? 0x1a4a66 : 0x0d2b3e}
      boatCount={5}
      boatSpread={3}
      fov={narrow ? 45 : 26}
      oceanFragments={18}
      oceanOpacity={0.65}
      oceanSize={narrow ? 20 : 30}
      showBoats
      showGrid={false}
      showWireframe
      waveAmplitude={0.2}
      waveSpeed={0.04}
      className="min-h-full"
    />
  );
}
