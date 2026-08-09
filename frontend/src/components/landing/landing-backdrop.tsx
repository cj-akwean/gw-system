"use client";

import { CanvasFractalGrid } from "@/components/ui/canvas-fractal-grid";
import { useTheme } from "@/lib/theme";

export function LandingBackdrop() {
  const { dark, mounted } = useTheme();

  if (!mounted) return null;

  return (
    <div className="pointer-events-none fixed inset-0" aria-hidden="true">
      <CanvasFractalGrid
        blendMode={dark ? "screen" : "multiply"}
        dotColor={dark ? "rgba(255, 255, 255, 1)" : "rgba(0, 0, 0, 1)"}
        dotOpacity={dark ? 0.35 : 0.5}
        dotSize={2}
        dotSpacing={18}
        enableGradient={false}
        enableMouseGlow
        enableNoise={false}
        glowColor={dark ? "rgba(125, 211, 252, 1)" : "rgba(70, 130, 180, 1)"}
        initialPerformance="high"
        waveIntensity={18}
        waveRadius={220}
      />
    </div>
  );
}
