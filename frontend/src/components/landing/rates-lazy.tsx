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
      // Load only after the section is 20% into the viewport. The rates
      // section starts exactly at the first viewport fold, so zero/positive
      // margins fire at page load — pulling the ~860KB three.js chunk into the
      // critical path on mobile AND desktop.
      { rootMargin: "0px 0px -20% 0px" }
    );
    io.observe(el);
    return () => io.disconnect();
  }, []);

  return <div ref={ref}>{show && <RatesSection />}</div>;
}
