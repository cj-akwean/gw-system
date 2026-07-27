"use client";

import { useState } from "react";
import { FlippingCard } from "@/components/ui/flipping-card";
import { AuthPage } from "@/components/auth";

export default function AuthRoute() {
  const [isFlipped, setIsFlipped] = useState(false);

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
      </div>
    </div>
  );
}
