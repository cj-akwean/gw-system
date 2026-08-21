"use client";

import { useCallback, useEffect, useRef, useState } from "react";

/**
 * Warns the user when they try to leave the page with unsaved changes.
 *
 * Two layers, matching best practice:
 * 1. `beforeunload` — covers closing the tab, refreshing, and external nav.
 * 2. In-app (App Router) navigation — `requestNavigate` shows a confirm dialog
 *    before running the callback, unless the page is clean.
 *
 * Usage:
 *   const { requestNavigate, pending, confirmLeave, cancelLeave } =
 *     useUnsavedChanges(isDirty);
 *   // navigate: requestNavigate(() => router.push("/dashboard"))
 *   // render: <UnsavedChangesDialog pending={pending} onConfirm={confirmLeave} onCancel={cancelLeave} />
 *   // the parent owns `isDirty` and clears it after a successful save
 */
export function useUnsavedChanges(active: boolean) {
  const [pending, setPending] = useState(false);
  const callbackRef = useRef<(() => void) | null>(null);

  useEffect(() => {
    if (!active) return;

    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };

    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [active]);

  const requestNavigate = useCallback(
    (callback: () => void) => {
      if (active) {
        callbackRef.current = callback;
        setPending(true);
      } else {
        callback();
      }
    },
    [active]
  );

  const confirmLeave = useCallback(() => {
    setPending(false);
    callbackRef.current?.();
    callbackRef.current = null;
  }, []);

  const cancelLeave = useCallback(() => {
    setPending(false);
    callbackRef.current = null;
  }, []);

  return { requestNavigate, pending, confirmLeave, cancelLeave };
}
