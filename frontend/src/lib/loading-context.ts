"use client";

import { createContext, useContext } from "react";

export const LoadingContext = createContext(false);

export function useLoadingComplete() {
  return useContext(LoadingContext);
}
