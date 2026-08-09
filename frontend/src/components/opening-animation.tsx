"use client";

import { useCallback, useState, type ReactNode } from "react";
import { LoadingScreen } from "@/components/loading-screen";
import { LoadingContext } from "@/lib/loading-context";

export function OpeningAnimation({ children }: { children: ReactNode }) {
  const [loadingComplete, setLoadingComplete] = useState(false);

  const handleLoadingDone = useCallback(() => {
    setLoadingComplete(true);
  }, []);

  return (
    <>
      <LoadingScreen onDone={handleLoadingDone} />
      <LoadingContext.Provider value={loadingComplete}>
        {children}
      </LoadingContext.Provider>
    </>
  );
}
