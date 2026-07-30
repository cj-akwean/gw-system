"use client";

import { useState } from "react";
import { FlippingCard } from "@/components/ui/flipping-card";
import { AuthPage } from "@/components/auth";
import { useAuth } from "@/lib/auth-context";

function SuccessDialog({ onClose }: { onClose: () => void }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="rounded-lg bg-white p-8 text-center shadow-xl dark:bg-neutral-900">
        <div className="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300">
          <svg className="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 className="mb-2 text-lg font-semibold">Sign in successful!</h2>
        <p className="mb-6 text-sm text-muted-foreground">
          You are now logged in.
        </p>
        <button
          onClick={onClose}
          className="rounded-md bg-primary px-6 py-2 text-sm font-medium text-white hover:bg-primary/90"
        >
          Continue
        </button>
      </div>
    </div>
  );
}

function AuthContent() {
  const [isFlipped, setIsFlipped] = useState(false);
  const [showSuccess, setShowSuccess] = useState(true);
  const { isAuthenticated, logout } = useAuth();

  return (
    <div className="flex min-h-screen w-full items-center justify-center p-6"
      style={{ background: "var(--bg)" }}
    >
      <div
        className="pointer-events-none fixed inset-0"
        style={{ background: "var(--glow) no-repeat", filter: "blur(80px)" }}
      />
      <div
        className="pointer-events-none fixed inset-0"
        style={{
          backgroundImage:
            "radial-gradient(circle at 1px 1px, var(--dot) 1px, transparent 0)",
          backgroundSize: "20px 20px",
        }}
      />
      <div className="relative z-10">
        {isAuthenticated ? (
          <div className="flex flex-col items-center gap-4">
            <p className="text-lg">You are signed in.</p>
            <button
              onClick={() => logout()}
              className="rounded-md bg-primary px-6 py-2 text-sm font-medium text-white hover:bg-primary/90"
            >
              Sign Out
            </button>
          </div>
        ) : (
          <FlippingCard
            isFlipped={isFlipped}
            width={400}
            height={520}
            frontContent={
              <AuthPage
                mode="login"
                onToggleMode={() => setIsFlipped(true)}
              />
            }
            backContent={
              <AuthPage
                mode="signup"
                onToggleMode={() => setIsFlipped(false)}
              />
            }
          />
        )}
      </div>

      {isAuthenticated && showSuccess && <SuccessDialog onClose={() => setShowSuccess(false)} />}
    </div>
  );
}

export default function AuthRoute() {
  return <AuthContent />;
}
