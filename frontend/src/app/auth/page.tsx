"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { FlippingCard } from "@/components/ui/flipping-card";
import { AuthPage } from "@/components/auth";
import { useAuth } from "@/lib/auth-context";

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
        {!ready ? null : (
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
    </div>
  );
}

export default function AuthRoute() {
  return <AuthContent />;
}