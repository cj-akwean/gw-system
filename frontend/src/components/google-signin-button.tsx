"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useAuth } from "@/lib/auth-context";

// `hl=en` forces English button/One Tap text regardless of the browser or
// Google-account language (otherwise GIS localizes to e.g. Tagalog).
const GIS_SCRIPT_URL = "https://accounts.google.com/gsi/client?hl=en";

/**
 * Client-side "Sign in with Google" button (Google Identity Services). Loads
 * the GIS script lazily, renders Google's official branded button, and hands
 * the returned ID token to the backend via `loginWithGoogle`. Hidden entirely
 * when NEXT_PUBLIC_GOOGLE_CLIENT_ID is not configured.
 */
export function GoogleSignInButton() {
  const { loginWithGoogle } = useAuth();
  const buttonRef = useRef<HTMLDivElement | null>(null);
  const [error, setError] = useState("");
  const [scriptError, setScriptError] = useState(false);
  const clientId = process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID;

  const handleCredential = useCallback(
    (response: { credential: string }) => {
      setError("");
      loginWithGoogle(response.credential).catch((err: unknown) => {
        setError(err instanceof Error ? err.message : "Google sign-in failed.");
      });
    },
    [loginWithGoogle]
  );

  useEffect(() => {
    if (!clientId || !buttonRef.current) return;

    const renderButton = () => {
      const el = buttonRef.current;
      if (!el) return;
      if (!window.google?.accounts?.id) {
        setScriptError(true);
        return;
      }
      // GIS renderButton appends an iframe; clear first so a StrictMode
      // remount never stacks a second button.
      el.replaceChildren();
      window.google.accounts.id.initialize({
        client_id: clientId,
        callback: handleCredential,
        error_callback: (err) => {
          setError(
            err?.message || "Google sign-in was cancelled or failed. Please try again."
          );
        },
      });
      window.google.accounts.id.renderButton(el, {
        theme: "outline",
        size: "large",
        shape: "rectangular",
        logo_alignment: "left",
        text: "signin_with",
        locale: "en",
        width: Math.max(el.offsetWidth, 240),
      });
    };

    if (window.google?.accounts?.id) {
      renderButton();
      return;
    }

    const script = document.createElement("script");
    script.src = GIS_SCRIPT_URL;
    script.async = true;
    script.defer = true;
    script.onload = () => {
      if (window.google?.accounts?.id) {
        renderButton();
      } else {
        setScriptError(true);
      }
    };
    script.onerror = () => setScriptError(true);
    document.head.appendChild(script);

    return () => {
      script.remove();
    };
  }, [clientId, handleCredential]);

  if (!clientId) return null;

  return (
    <div className="w-full space-y-2">
      <div ref={buttonRef} className="w-full" />
      {error && (
        <p className="text-center text-sm text-red-500" role="alert">
          {error}
        </p>
      )}
      {scriptError && (
        <p className="text-center text-sm text-red-500" role="alert">
          Could not load Google sign-in. Please try again or log in with your email.
        </p>
      )}
    </div>
  );
}