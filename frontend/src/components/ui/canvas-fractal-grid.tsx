"use client";

import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { AnimatePresence, motion, useAnimation } from "motion/react";

interface GradientStop {
  color: string;
  position: number;
}

interface GradientType {
  stops: GradientStop[];
  centerX: number;
  centerY: number;
}

interface CanvasFractalGridProps {
  /** Size of each dot in pixels */
  dotSize?: number;
  /** Spacing between dots in pixels */
  dotSpacing?: number;
  /** Opacity of dots (0-1) */
  dotOpacity?: number;
  /** Duration of the background gradient animation in seconds */
  gradientAnimationDuration?: number;
  /** Intensity of the wave effect when hovering */
  waveIntensity?: number;
  /** Radius of the wave effect in pixels */
  waveRadius?: number;
  /** Array of gradient configurations for the background */
  gradients?: GradientType[];
  /** Color of the dots (supports any valid CSS color) */
  dotColor?: string;
  /** Color of the dot glow effect (supports any valid CSS color) */
  glowColor?: string;
  /** Enable or disable the noise overlay */
  enableNoise?: boolean;
  /** Opacity of the noise overlay (0-1) */
  noiseOpacity?: number;
  /** Enable or disable the mouse glow effect */
  enableMouseGlow?: boolean;
  /** Set the initial performance level */
  initialPerformance?: "low" | "medium" | "high";
  /** Enable or disable the gradient animation */
  enableGradient?: boolean;
  /** Canvas blend mode — multiply for light backgrounds, screen for dark */
  blendMode?: "multiply" | "screen" | "normal";
  /** Maximum animation frames per second (mobile can cap at 30) */
  maxFps?: number;
}

const NoiseSVG = React.memo(() => (
  <svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%">
    <filter id="noise">
      <feTurbulence
        type="fractalNoise"
        baseFrequency="0.65"
        numOctaves="3"
        stitchTiles="stitch"
      />
    </filter>
    <rect width="100%" height="100%" filter="url(#noise)" />
  </svg>
));

NoiseSVG.displayName = "NoiseSVG";

const NoiseOverlay: React.FC<{ opacity: number }> = ({ opacity }) => (
  <div
    className="absolute inset-0 h-full w-full mix-blend-overlay"
    style={{ opacity }}
  >
    <NoiseSVG />
  </div>
);

const useResponsive = () => {
  const [windowSize, setWindowSize] = useState({
    width: 0,
    height: 0,
  });

  useEffect(() => {
    if (typeof window === "undefined") return;

    const handleResize = () => {
      setWindowSize({
        width: window.innerWidth,
        height: window.innerHeight,
      });
    };

    handleResize();

    window.addEventListener("resize", handleResize);
    return () => window.removeEventListener("resize", handleResize);
  }, []);

  return {
    isMobile: windowSize.width < 768,
    isTablet: windowSize.width >= 768 && windowSize.width < 1024,
    isDesktop: windowSize.width >= 1024,
  };
};

const usePerformance = (
  initialPerformance: "low" | "medium" | "high" = "medium"
) => {
  const [performance, setPerformance] = useState(initialPerformance);

  useEffect(() => {
    if (typeof window === "undefined") return;

    let frameCount = 0;
    let lastTime = globalThis.performance.now();
    let framerId: number;

    const measureFps = (time: number) => {
      frameCount++;
      if (time - lastTime > 1000) {
        const fps = Math.round((frameCount * 1000) / (time - lastTime));
        frameCount = 0;
        lastTime = time;
        if (fps < 30) {
          setPerformance((prev) => (prev === "low" ? prev : "low"));
        } else if (fps < 50) {
          setPerformance((prev) =>
            prev === "low" || prev === "medium" ? prev : "medium"
          );
        } else {
          setPerformance((prev) => (prev === "high" ? prev : "high"));
        }
      }
      framerId = requestAnimationFrame(measureFps);
    };

    framerId = requestAnimationFrame(measureFps);

    return () => cancelAnimationFrame(framerId);
  }, []);

  return { performance };
};

const Gradient: React.FC<{
  gradients: GradientType[];
  animationDuration: number;
}> = React.memo(({ gradients, animationDuration }) => {
  const controls = useAnimation();

  useEffect(() => {
    controls.start({
      background: gradients.map(
        (g) =>
          `radial-gradient(circle at ${g.centerX}% ${g.centerY}%, ${g.stops
            .map((s) => `${s.color} ${s.position}%`)
            .join(", ")})`
      ),
      transition: {
        duration: animationDuration,
        repeat: Infinity,
        repeatType: "reverse",
        ease: "linear",
      },
    });
  }, [controls, gradients, animationDuration]);

  return (
    <motion.div className="absolute inset-0 h-full w-full" animate={controls} />
  );
});

Gradient.displayName = "Gradient";

const DotCanvas: React.FC<{
  dotSize: number;
  dotSpacing: number;
  dotOpacity: number;
  waveIntensity: number;
  waveRadius: number;
  dotColor: string;
  glowColor: string;
  performance: "low" | "medium" | "high";
  mousePos: { x: number; y: number };
  blendMode: "multiply" | "screen" | "normal";
  maxFps: number;
}> = React.memo(
  ({
    dotSize,
    dotSpacing,
    dotOpacity,
    waveIntensity,
    waveRadius,
    dotColor,
    glowColor,
    performance,
    mousePos,
    blendMode,
    maxFps,
  }) => {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const animationRef = useRef<number | null>(null);

    const drawDots = useCallback(
      (ctx: CanvasRenderingContext2D, time: number) => {
        const { width, height } = ctx.canvas;
        ctx.clearRect(0, 0, width, height);

        const performanceSettings = {
          low: { skip: 3 },
          medium: { skip: 2 },
          high: { skip: 1 },
        };

        const skip = performanceSettings[performance].skip;

        const cols = Math.ceil(width / dotSpacing);
        const rows = Math.ceil(height / dotSpacing);

        const centerX = mousePos.x * width;
        const centerY = mousePos.y * height;

        for (let i = 0; i < cols; i += skip) {
          for (let j = 0; j < rows; j += skip) {
            const x = i * dotSpacing;
            const y = j * dotSpacing;

            const distanceX = x - centerX;
            const distanceY = y - centerY;
            const distance = Math.sqrt(
              distanceX * distanceX + distanceY * distanceY
            );

            let dotX = x;
            let dotY = y;

            if (distance < waveRadius) {
              const waveStrength = Math.pow(1 - distance / waveRadius, 2);
              const angle = Math.atan2(distanceY, distanceX);
              const waveOffset =
                Math.sin(distance * 0.05 - time * 0.005) *
                waveIntensity *
                waveStrength;
              dotX += Math.cos(angle) * waveOffset;
              dotY += Math.sin(angle) * waveOffset;

              const glowRadius = dotSize * (1 + waveStrength);
              const gradient = ctx.createRadialGradient(
                dotX,
                dotY,
                0,
                dotX,
                dotY,
                glowRadius
              );
              gradient.addColorStop(
                0,
                glowColor.replace("1)", `${dotOpacity * (1 + waveStrength)})`)
              );
              gradient.addColorStop(1, glowColor.replace("1)", "0)"));
              ctx.fillStyle = gradient;
            } else {
              ctx.fillStyle = dotColor.replace("1)", `${dotOpacity})`);
            }

            ctx.beginPath();
            ctx.arc(dotX, dotY, dotSize / 2, 0, Math.PI * 2);
            ctx.fill();
          }
        }
      },
      [
        dotSize,
        dotSpacing,
        dotOpacity,
        waveIntensity,
        waveRadius,
        dotColor,
        glowColor,
        performance,
        mousePos,
      ]
    );

    useEffect(() => {
      if (typeof window === "undefined") return;

      const canvas = canvasRef.current;
      if (!canvas) return;

      const ctx = canvas.getContext("2d");
      if (!ctx) return;

      const resizeCanvas = () => {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
      };

      resizeCanvas();
      window.addEventListener("resize", resizeCanvas);

      let lastTime = 0;
      const frameInterval = 1000 / Math.max(1, maxFps);
      const animate = (time: number) => {
        if (!document.hidden && time - lastTime > frameInterval) {
          drawDots(ctx, time);
          lastTime = time;
        }
        animationRef.current = requestAnimationFrame(animate);
      };

      animationRef.current = requestAnimationFrame(animate);

      return () => {
        window.removeEventListener("resize", resizeCanvas);
        if (animationRef.current) {
          cancelAnimationFrame(animationRef.current);
        }
      };
    }, [drawDots, maxFps]);

    return (
      <canvas
        ref={canvasRef}
        className="absolute inset-0 h-full w-full"
        style={{ mixBlendMode: blendMode }}
      />
    );
  }
);

DotCanvas.displayName = "DotCanvas";

const MouseGlow: React.FC<{
  glowColor: string;
  mousePos: { x: number; y: number };
}> = React.memo(({ glowColor, mousePos }) => (
  <>
    <div
      className="pointer-events-none absolute h-40 w-40 rounded-full"
      style={{
        background: `radial-gradient(circle, ${glowColor.replace(
          "1)",
          "0.2)"
        )} 0%, ${glowColor.replace("1)", "0)")} 70%)`,
        left: `${mousePos.x * 100}%`,
        top: `${mousePos.y * 100}%`,
        transform: "translate(-50%, -50%)",
        filter: "blur(10px)",
      }}
    />
    <div
      className="pointer-events-none absolute h-20 w-20 rounded-full"
      style={{
        background: `radial-gradient(circle, ${glowColor.replace(
          "1)",
          "0.4)"
        )} 0%, ${glowColor.replace("1)", "0)")} 70%)`,
        left: `${mousePos.x * 100}%`,
        top: `${mousePos.y * 100}%`,
        transform: "translate(-50%, -50%)",
      }}
    />
  </>
));

MouseGlow.displayName = "MouseGlow";

const defaultGradients: GradientType[] = [
  {
    stops: [
      { color: "#FFD6A5", position: 0 },
      { color: "#FFADAD", position: 25 },
      { color: "#FFC6FF", position: 50 },
      { color: "transparent", position: 75 },
    ],
    centerX: 50,
    centerY: 50,
  },
  {
    stops: [
      { color: "#A0C4FF", position: 0 },
      { color: "#BDB2FF", position: 25 },
      { color: "#CAFFBF", position: 50 },
      { color: "transparent", position: 75 },
    ],
    centerX: 60,
    centerY: 40,
  },
  {
    stops: [
      { color: "#9BF6FF", position: 0 },
      { color: "#FDFFB6", position: 25 },
      { color: "#FFAFCC", position: 50 },
      { color: "transparent", position: 75 },
    ],
    centerX: 40,
    centerY: 60,
  },
];

export function CanvasFractalGrid({
  dotSize = 4,
  dotSpacing = 20,
  dotOpacity = 0.3,
  gradientAnimationDuration = 20,
  waveIntensity = 30,
  waveRadius = 200,
  gradients = defaultGradients,
  dotColor = "rgba(100, 100, 255, 1)",
  glowColor = "rgba(100, 100, 255, 1)",
  enableNoise = true,
  noiseOpacity = 0.03,
  enableMouseGlow = true,
  initialPerformance = "medium",
  enableGradient = false,
  blendMode = "multiply",
  maxFps = 60,
}: CanvasFractalGridProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const { isMobile, isTablet } = useResponsive();
  const { performance } = usePerformance(initialPerformance);
  const [mousePos, setMousePos] = useState({ x: 0, y: 0 });

  const handlePointer = useCallback((clientX: number, clientY: number) => {
    const rect = containerRef.current?.getBoundingClientRect();
    const width = rect?.width ?? 0;
    const height = rect?.height ?? 0;
    const x = width ? (clientX - (rect?.left ?? 0)) / width : 0.5;
    const y = height ? (clientY - (rect?.top ?? 0)) / height : 0.5;
    setMousePos({ x, y });
  }, []);

  const handleMouseMove = useCallback((event: MouseEvent) => {
    handlePointer(event.clientX, event.clientY);
  }, [handlePointer]);

  const handleTouchMove = useCallback(
    (event: TouchEvent) => {
      const touch = event.touches[0];
      if (touch) handlePointer(touch.clientX, touch.clientY);
    },
    [handlePointer]
  );

  useEffect(() => {
    if (typeof window === "undefined") return;

    window.addEventListener("mousemove", handleMouseMove);
    window.addEventListener("touchstart", handleTouchMove, { passive: true });
    window.addEventListener("touchmove", handleTouchMove, { passive: true });
    return () => {
      window.removeEventListener("mousemove", handleMouseMove);
      window.removeEventListener("touchstart", handleTouchMove);
      window.removeEventListener("touchmove", handleTouchMove);
    };
  }, [handleMouseMove, handleTouchMove]);

  const responsiveDotSize = useMemo(() => {
    if (isMobile) return dotSize * 0.75;
    if (isTablet) return dotSize * 0.9;
    return dotSize;
  }, [isMobile, isTablet, dotSize]);

  const responsiveDotSpacing = useMemo(() => {
    if (isMobile) return dotSpacing * 1.5;
    if (isTablet) return dotSpacing * 1.25;
    return dotSpacing;
  }, [isMobile, isTablet, dotSpacing]);

  return (
    <AnimatePresence>
      <motion.div
        ref={containerRef}
        key="landing-animation"
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        transition={{ duration: 1.5, ease: "easeOut" }}
        className="absolute inset-0 h-full w-full overflow-hidden"
      >
        {enableGradient && (
          <Gradient
            gradients={gradients}
            animationDuration={gradientAnimationDuration}
          />
        )}
        {enableGradient && (
          <motion.div
            className="absolute inset-0 h-full w-full"
            style={{
              background: "radial-gradient(circle, transparent, #FFFFFF)",
              backgroundSize: "100% 100%",
              backgroundPosition: "center",
              mixBlendMode: "overlay",
            }}
            animate={{
              backgroundPosition: `${mousePos.x * 100}% ${mousePos.y * 100}%`,
            }}
          />
        )}
        <DotCanvas
          dotSize={responsiveDotSize}
          dotSpacing={responsiveDotSpacing}
          dotOpacity={dotOpacity}
          waveIntensity={waveIntensity}
          waveRadius={waveRadius}
          dotColor={dotColor}
          glowColor={glowColor}
          performance={performance}
          mousePos={mousePos}
          blendMode={blendMode}
          maxFps={maxFps}
        />
        {enableNoise && <NoiseOverlay opacity={noiseOpacity} />}
        {enableMouseGlow && (
          <MouseGlow glowColor={glowColor} mousePos={mousePos} />
        )}
      </motion.div>
    </AnimatePresence>
  );
}

export default React.memo(CanvasFractalGrid);
