"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { FlippingCard } from "@/components/ui/flipping-card";
import { AuthPage } from "@/components/auth";
import { ThemeToggle } from "@/components/theme-toggle";
import { Loader2 } from "lucide-react";
import { useAuth } from "@/lib/auth-context";

import PasswordChangedNotice from "@/components/portal/password-changed-notice";

function AuthContent() {
  const [isFlipped, setIsFlipped] = useState(false);
  const { isAuthenticated, ready, user } = useAuth();
  const router = useRouter();
  const redirected = useRef(false);

  useEffect(() => {
    if (ready && isAuthenticated && !redirected.current) {
      redirected.current = true;
      router.replace(user?.avatar_id ? "/dashboard" : "/onboarding");
    }
  }, [ready, isAuthenticated, router, user?.avatar_id]);

  return (
    <div className="flex min-h-screen w-full items-center justify-center p-6"
      style={{ background: "var(--bg)" }}
    >
      <div
        className="pointer-events-none fixed inset-0"
        style={{ background: "var(--glow) no-repeat", filter: "blur(var(--glow-blur))" }}
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
        <div className="fixed top-6 right-6 z-50">
          <ThemeToggle />
        </div>
        <Suspense fallback={null}>
          <PasswordChangedNotice />
        </Suspense>
        {!ready ? (
          <div className="flex h-[520px] w-[400px] max-w-full items-center justify-center">
            <Loader2 aria-hidden className="size-6 animate-spin text-primary" />
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
        <div className="mt-6 flex justify-center">
          <Link
            href="/"
            className="text-sm text-muted-foreground underline underline-offset-4 transition-colors hover:text-primary"
          >
            ← Back to home
          </Link>
        </div>
      </div>
    </div>
  );
}

export default function AuthRoute() {
  return <AuthContent />;
}