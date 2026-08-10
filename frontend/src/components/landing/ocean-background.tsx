"use client";

import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";

// The three.js ocean is the only heavy part of the rates section. It's
// absolutely positioned (no layout impact), so it can be deferred until the
// section scrolls into view WITHOUT causing a layout shift — the cards render
// statically from the start.
const LazyOcean = dynamic(
  () => import("./ocean-scene").then((m) => m.OceanScene),
  {
    loading: () => null,
    ssr: false,
  }
);

export function OceanBackground() {
  const [show, setShow] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  // Download + parse the three.js chunk during idle right after mount, so the
  // first scroll into the rates section doesn't stall on a mid-scroll fetch.
  useEffect(() => {
    let cancelled = false;
    const prefetch = () => {
      if (cancelled) return;
      import("./ocean-scene").catch(() => {});
    };
    const id =
      typeof requestIdleCallback === "function"
        ? requestIdleCallback(prefetch, { timeout: 3000 })
        : (setTimeout(prefetch, 2000) as unknown as number);
    return () => {
      cancelled = true;
      if (typeof requestIdleCallback === "function") {
        cancelIdleCallback(id);
      } else {
        clearTimeout(id);
      }
    };
  }, []);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const io = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setShow(true);
          io.disconnect();
        }
      },
      // Load once the section is 20% into the viewport — the section starts
      // at the first viewport fold, so zero/positive margins fire at load.
      { rootMargin: "0px 0px -20% 0px" }
    );
    io.observe(el);
    return () => io.disconnect();
  }, []);

  return (
    <div ref={ref} className="absolute inset-0">
      {show && <LazyOcean />}
    </div>
  );
}
