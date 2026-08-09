"use client";

import dynamic from "next/dynamic";
import { useEffect, useRef, useState } from "react";

const RatesSection = dynamic(
  () => import("./rates-section").then((m) => m.RatesSection),
  {
    loading: () => (
      <div aria-hidden className="min-h-[600px] md:min-h-[700px]" />
    ),
    ssr: false,
  }
);

export function RatesLazy() {
  const [show, setShow] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

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
      { rootMargin: "600px" }
    );
    io.observe(el);
    return () => io.disconnect();
  }, []);

  return <div ref={ref}>{show && <RatesSection />}</div>;
}
